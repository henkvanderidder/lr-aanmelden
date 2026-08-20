<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use App\Mail\AanmeldingMail;
use App\Mail\AanmeldingMailError;
use Exception;

class ProcessOpenstreetmap extends ProcessBaseJob
{
    // use Queueable;

    public $reviverUsers = [];
    public $postcodeLijst = [];

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
        // Log::info('OpenStreetMap: job created.');
    }

    private function leesReviverUsers($departmentId) {

        //
        $doorgaan = true;

        // startpunt
        $offset=0;
        $limit=50;
        while ($doorgaan == true) {
            try {
                $url = env('SNIPEIT_URL') . "/api/v1/users?limit=$limit&offset=$offset&department_id=$departmentId";
                $token = env('SNIPEIT_TOKEN');
                Log::info('leesReviverUsers: SnipeIT url ' . $url . '. ');

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ])->get($url);

                $data = [];
                $gevonden = 0;
                if ($response->successful()) {
                    $responseData = $response->json();
                    $data = $responseData['rows'] ?? [];
                    $gevonden = count($data);
                    //Log::info('leesReviverUsers: SnipeIT response for users: ' . $gevonden . ' users.');
                    //Log::info('LaptopAanmelden: SnipeIT response data for users: ' . print_r($data, true) );
                    $offset += $limit;
                    if  ($offset >= 1000) $gevonden = 0; // beveiliging, niet meer dan 1000 laptop revivers
                } else {
                    $gevonden = 0;
                    Log::error('leesReviverUsers: SnipeIT Failed to read users: ' . $response->status());
                }
            } catch (Exception $e) {
                $gevonden = 0;
                Log::error('leesReviverUsers: SnipeIT error: ' . $e->getMessage());
            }
            if ($gevonden > 0) {
                // voeg toe aan reviverUsers
                Log::info('leesReviverUsers: te verwerken users gevonden : ' . $gevonden . ' users.');
                foreach ($data as $row) {
                    $reviver = array(
                        'id' =>  $row['id'],
                        'naam' => $row['name'],
                        'postcode' => $row['zip'],
                        'afdeling' => $row['department']['name'],
                        'aantal' => $row['assets_count'],
                    );
                    $this->reviverUsers[] = $reviver;
                }
            }
            // sleep(5); // wacht 5 seconden voor snipeit max attemps
            if ($gevonden == 0) $doorgaan = false;
        } // while $doorgaan


    }

    private function verzamelReviverUsers() {
        Log::info('OpenStreetMap: verzamelReviverUsers called');

        // bepaal afdeling Centrale Administratie/Reviver
        $afdelingId = $this->readSnipeITPartforId('departments', 'Centrale Administratie/Reviver', 'id', 'asc');
        Log::info('OpenStreetMap: verzamelReviverUsers afdeling CentraleAdministratie/Reviver: '.$afdelingId);
        // bepaal de gebruikers die in Snipe-IT de afdeling Centrale Administratie/Reviver hebben
        $this->leesReviverUsers($afdelingId);
        Log::info('OpenStreetMap: verzamelde aantal ReviverUsers: '.count($this->reviverUsers));
        // Log::info('OpenStreetMap: verzamelde ReviverUsers: '.print_r($this->reviverUsers,1));

        // bepaal afdeling Laptop Reviver
        $afdelingId = $this->readSnipeITPartforId('departments', 'Laptop Reviver', 'id', 'asc');
        Log::info('OpenStreetMap: verzamelReviverUsers afdeling Laptop Reviver: '.$afdelingId);
        // bepaal de gebruikers die in Snipe-IT de afdeling Laptop Reviver hebben
        $this->leesReviverUsers($afdelingId);
        Log::info('OpenStreetMap: verzamelde aantal ReviverUsers: '.count($this->reviverUsers));
        Log::info('OpenStreetMap: verzamelde ReviverUsers: '.print_r($this->reviverUsers[0],1));

        /*
            [2026-08-19 13:53:40] local.INFO: OpenStreetMap: verzamelde ReviverUsers: Array
            (
                [0] => Array
                    (
                        [id] => 3
                        [naam] => Rinus Loof-van Overmeeren
                        [postcode] => 1311
                        [afdeling] => Centrale Administratie/Reviver
                    )

                [1] => Array
                    (
                        [id] => 13
                        [naam] => Jan Hoogendoorn
                        [postcode] => 7431
                        [afdeling] => Centrale Administratie/Reviver
                    )

        */
    }

    private function verzamelPostcodeLijst() {
        // verrijke met longitude / latitude
        $postcodesNL = Storage::disk('local')->get('postcodesNL.csv');
        $postcodes = explode("\n",$postcodesNL);
        // Log::info('OpenStreetMap: verzamelPostcodeLijst postcodes '. print_r($postcodes,1));
        foreach ($postcodes as $postcode) {
            $arPostcode = str_getcsv($postcode,",");
            // Log::info('OpenStreetMap: verzamelPostcodeLijst regel'. print_r($arPostcode,1));
            $this->postcodeLijst[] = $arPostcode;
        }
        //Log::info('OpenStreetMap: verzamelPostcodeLijst postcodeLijst '. print_r($this->postcodeLijst[0],1));

        /*
            [2026-08-19 13:33:40] local.INFO: OpenStreetMap: verzamelReviverUsers postcodeLijst Array
            (
                [0] => Array
                    (
                        [0] => ﻿"Postal Code"
                        [1] => Place Name
                        [2] => Province
                        [3] => Municipality
                        [4] => Latitude
                        [5] => Longitude
                    )

                [1] => Array
                    (
                        [0] => 9401
                        [1] => Assen
                        [2] => Drenthe
                        [3] => 
                        [4] => 52.9939
                        [5] => 6.5625
                    )
        */

    }

    private function verrijkMetPositie()
    {
         // doorloop users
        $aantal = count($this->reviverUsers);
        for ($i=0;$i<$aantal;$i++) {
            $this->reviverUsers[$i]['latitude'] = -1;
            $this->reviverUsers[$i]['longitude'] = -1;
            // doorloop de postcodes
            foreach($this->postcodeLijst as $arPostcode) {
                if (substr($this->reviverUsers[$i]['postcode'],0,4) == $arPostcode[0]) {
                    $this->reviverUsers[$i]['latitude'] = $arPostcode[4];
                    $this->reviverUsers[$i]['longitude'] = $arPostcode[5];
                }
            }

        }
        //Log::info('OpenStreetMap: verrijkte ReviverUsers: '.print_r($this->reviverUsers[0],1));
        /*
            [2026-08-19 13:58:05] local.INFO: OpenStreetMap: verrijkte ReviverUsers: Array
            (
                [0] => Array
                    (
                        [id] => 3
                        [naam] => Rinus Loof-van Overmeeren
                        [postcode] => 1311
                        [afdeling] => Centrale Administratie/Reviver
                        [aantal] => 1
                        [latitude] => 52.3681
                        [longitude] => 5.1796
                    )

                [5] => Array
                    (
                        [id] => 773
                        [naam] => Zoila Daugherty
                        [postcode] => 5189
                        [afdeling] => Laptop Reviver
                        [aantal] => 0
                        [latitude] => -1
                        [longitude] => -1
                    )

        */

    }

        private function leesGereserveerdeUserAssets($userId,$statusId) {

        //
        $gereserveerd = 0;

        // startpunt
        $offset=0;
        $limit=50;
        try {
            $url = env('SNIPEIT_URL') . "/api/v1/hardware?limit=$limit&offset=$offset&assigned_to=$userId&assigned_type=App%5CModels%5CUser";
            $token = env('SNIPEIT_TOKEN');
            // Log::info('leesGereserveerdeUserAssets: SnipeIT url ' . $url . '. ');

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])->get($url);

            $data = [];
            if ($response->successful()) {
                $responseData = $response->json();
                $data = $responseData['rows'] ?? [];
                $aantal = count($data);
                // Log::info("leesGereserveerdeUserAssets: SnipeIT response for user $userId-$statusId: " . $aantal . ' hardware.');
                if ($userId == 63 || $userId == 95 || $userId == 122) {
                    //Log::info('leesGereserveerdeUserAssets: SnipeIT url ' . $url . '. ');
                    //Log::info("leesGereserveerdeUserAssets: SnipeIT response data for user $userId-$statusId: " . print_r($data, true) );
                }
                foreach($data as $asset) {
                    if (isset($asset['status']['id'])) {
                        if ($asset['status']['id'] == $statusId) {
                            $gereserveerd++;
                        }
                    }
                }
            } 
        }
        catch (Exception $e) {
            Log::error('leesGereserveerdeUserAssets: SnipeIT error: ' . $e->getMessage());
        }
        // sleep(5); // wacht 5 seconden voor snipeit max attemps

        return $gereserveerd;
    }

    private function bepaalGereserveerd() {
        Log::info('OpenStreetMap: bepaalGeserveerd called');

        // bepaal afdeling Centrale Administratie/Reviver
        $statusId = $this->readSnipeITPartforId('statuslabels', 'Gereserveerd', 'id', 'asc');
        Log::info('OpenStreetMap: bepaalGereserveerd statuslabel: '.$statusId);
        
        // doorloop users
        $aantal = count($this->reviverUsers);
        for ($i=0;$i<$aantal;$i++) {
            $this->reviverUsers[$i]['gereserveerd'] = 0;
            $userId = $this->reviverUsers[$i]['id'];
            if ($this->reviverUsers[$i]['aantal'] > 0) {
                $gereserveerd = $this->leesGereserveerdeUserAssets($userId,$statusId);
                $this->reviverUsers[$i]['gereserveerd'] = $gereserveerd;
            }
            if (($userId == 63) or ($userId == 95) or ($userId == 122)  or ($userId == 156) or ($userId == 321)) {
                //Log::info("bepaalGereserveeerd: resultaat for user $userId: " . print_r($this->reviverUsers[$i], true) );
            }

        }
        //Log::info("bepaalGereserveeerd: resultaat: " . print_r($this->reviverUsers[0], true) );
    }

    public function handle(): void {
        Log::info('OpenStreetMap: job gestart '.date("Y-m-d H:i:s").'.');
        $this->verzamelReviverUsers();
        Log::info('OpenStreetMap: aantal verzamelde users: ' . count($this->reviverUsers));

        $this->verzamelPostcodeLijst();
        Log::info('OpenStreetMap: aantal verzamelde postcodes: ' . count($this->postcodeLijst));

        $this->verrijkMetPositie();
        Log::info('OpenStreetMap: aantal verzamelde users na verrijkMetPositie: ' . count($this->reviverUsers));

        $this->bepaalGereserveerd();
        Log::info('OpenStreetMap: aantal verzamelde users na bepaalgereserveerd: ' . count($this->reviverUsers));
        // Log::info("'OpenStreetMap: alle users: " . print_r($this->reviverUsers, true) );

        Log::info('OpenStreetMap: job finished '.date("Y-m-d H:i:s").'.');
    }
}

