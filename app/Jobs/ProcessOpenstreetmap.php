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
    public $aanvragerUsers = [];
    public $postcodeLijst = [];

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
        // Log::info('OpenStreetMap: job created.');
    }

    private function leesReviverUsers($soort, $departmentId) {

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
                Log::info("leesReviverUsers: te verwerken $soort gevonden : " . $gevonden . ' users.');
                foreach ($data as $row) {
                    $reviver = array(
                        'id' =>  $row['id'],
                        'username' => $row['username'],
                        'naam' => $row['name'],
                        'voornaam' => $row['first_name'],
                        'displayname' => $row['display_name'],
                        'postcode' => $row['zip'],
                        'plaats' => $row['city'],
                        'afdeling' => $row['department']['name'],
                        'aantal' => $row['assets_count'],
                    );
                    if ($soort == 'revivers') {
                        $this->reviverUsers[] = $reviver;
                    }
                    if ($soort == 'aanvragers') {
                        if ($row['manager'] > 0) {
                            // doe niets
                        } else {
                            // alleen zonder manager
                            $this->aanvragerUsers[] = $reviver;
                        }
                    }
    
                }
            }
            // sleep(5); // wacht 5 seconden voor snipeit max attemps
            if ($gevonden == 0) $doorgaan = false;
        } // while $doorgaan


    }

    private function verzamelAlleUsers() {
        Log::info('OpenStreetMap: verzamelAlleUsers called');

        // bepaal afdeling Centrale Administratie/Reviver
        $afdelingId = $this->readSnipeITPartforId('departments', 'Centrale Administratie/Reviver', 'id', 'asc');
        Log::info('OpenStreetMap: verzamelAlleUsers afdeling CentraleAdministratie/Reviver: '.$afdelingId);
        // bepaal de gebruikers die in Snipe-IT de afdeling Centrale Administratie/Reviver hebben
        $this->leesReviverUsers("revivers",$afdelingId);
        Log::info('OpenStreetMap: verzamelde aantal ReviverUsers: '.count($this->reviverUsers));
        // Log::info('OpenStreetMap: verzamelde ReviverUsers: '.print_r($this->reviverUsers,1));

        // bepaal afdeling Laptop Reviver
        $afdelingId = $this->readSnipeITPartforId('departments', 'Laptop Reviver', 'id', 'asc');
        Log::info('OpenStreetMap: verzamelAlleUsers afdeling Laptop Reviver: '.$afdelingId);
        // bepaal de gebruikers die in Snipe-IT de afdeling Laptop Reviver hebben
        $this->leesReviverUsers("revivers",$afdelingId);
        Log::info('OpenStreetMap: verzamelde aantal ReviverUsers: '.count($this->reviverUsers));
        Log::info('OpenStreetMap: verzamelde ReviverUsers: '.print_r($this->reviverUsers[0],1));

        // bepaal afdeling Laptopaanvrager
        $afdelingId = $this->readSnipeITPartforId('departments', 'Laptopaanvrager', 'id', 'asc');
        Log::info('OpenStreetMap: verzamelAlleUsers afdeling Laptopaanvrager: '.$afdelingId);
        // bepaal de gebruikers die in Snipe-IT de afdeling Laptopaanvrager hebben
        $this->leesReviverUsers("aanvragers",$afdelingId);
        Log::info('OpenStreetMap: verzamelde aantal AanvragerUsers: '.count($this->aanvragerUsers));
        Log::info('OpenStreetMap: verzamelde AanvragerUsers: '.print_r($this->aanvragerUsers[0],1));

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
        Log::info('OpenStreetMap: verzamelPostcodeLijst postcodeLijst '. print_r($this->postcodeLijst[1],1));

        /*
            [2026-08-19 13:33:40] local.INFO: OpenStreetMap: verzamelAlleUsers postcodeLijst Array
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

    private function verrijkReviversMetPositie()
    {
        // doorloop users
        $aantal = count($this->reviverUsers);
        for ($i=0;$i<$aantal;$i++) {
            $this->reviverUsers[$i]['latitude'] = -1;
            $this->reviverUsers[$i]['longitude'] = -1;
            // doorloop de postcodes
            foreach($this->postcodeLijst as $arPostcode) {
                if (substr($this->reviverUsers[$i]['postcode'],0,4) == $arPostcode[0]) {
                    if (isset($arPostcode[4]) && isset($arPostcode[5])) {
                        $this->reviverUsers[$i]['latitude'] = $arPostcode[4];
                        $this->reviverUsers[$i]['longitude'] = $arPostcode[5];
                    }
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

    private function verrijkAanvragersMetPositie()
    {
        // doorloop users
        $aantal = count($this->aanvragerUsers);
        for ($i=0;$i<$aantal;$i++) {
            $this->aanvragerUsers[$i]['latitude'] = -1;
            $this->aanvragerUsers[$i]['longitude'] = -1;
            // doorloop de postcodes
            foreach($this->postcodeLijst as $arPostcode) {
                if (substr($this->aanvragerUsers[$i]['postcode'],0,4) == $arPostcode[0]) {
                    if (isset($arPostcode[4]) && isset($arPostcode[5])) {
                        $this->aanvragerUsers[$i]['latitude'] = $arPostcode[4];
                        $this->aanvragerUsers[$i]['longitude'] = $arPostcode[5];
                    }
                }
            }

        }
        //Log::info('OpenStreetMap: verrijkte AanvragerUsers: '.print_r($this->aanvragerUsers[0],1));

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

    private function verrijkMetGereserveerd() {
        Log::info('OpenStreetMap: bepaalGeserveerd called');

        // bepaal afdeling Centrale Administratie/Reviver
        $statusId = $this->readSnipeITPartforId('statuslabels', 'Gereserveerd', 'id', 'asc');
        Log::info('OpenStreetMap: verrijkMetGereserveerd statuslabel: '.$statusId);
        
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

    public function schrijfReviversCSVbestand()
    {
        // Latitude is de Engelse term voor breedtegraad en longitude is de Engelse term voor lengtegraad

        Log::info('OpenStreetMap: schrijfReviversCSVbestand called');

        // $LR_OSM_lijst = Storage::disk('local')->get('LR_OSM_lijst.csv');
        // $arPostcode = str_getcsv($postcode,",");
        $kop = '"Afdeling","Voornaam","Plaats","Postcode","Activa","Postal Code","latitude","longitude"';
        $regels = $kop."\n";
        $uitvallers = $kop."\n";
        $voorraden = $kop."\n";
        $tel = 0;
        $uitval = 0;
        $voorraad = 0;

        // doorloop users
        $aantal = count($this->reviverUsers);
        foreach ($this->reviverUsers as $reviver) {
            $regel  = '"'.$reviver['voornaam'].'",';
            $regel .= '"'.$reviver['plaats'].'",';
            $regel .= '"'.substr($reviver['postcode'],0,4).'",';
            $regel .= intval($reviver['aantal']) -  intval($reviver['gereserveerd']).",";
            $regel .= '"'.$reviver['postcode'].'",';
            $regel .= $reviver['latitude'].",";
            $regel .= $reviver['longitude']."\n";
            if ($reviver['latitude'] > 0)  {
                $tel++;
                $regels .= '"'."Alle Revivers".'",'.$regel;
            }
            if ($reviver['latitude'] <= 0) {
                $uitval++;
                $uitvallers .= '"'."Revivers met foute postcode".'",'.$regel;
            } 
            if ((intval($reviver['aantal']) - intval($reviver['gereserveerd']) > 0)) {
                $voorraad++;
                $voorraden .= '"'."Revivers met laptop".'",'.$regel;
            }
        }

        Storage::disk('public')->put('LR_AlleRevivers_lijst.csv', $regels);
        Log::info("schrijfAlleReviversCSVbestand: aantal revivers: $tel" );

        Storage::disk('public')->put('LR_FouteRevivers_lijst.csv', $uitvallers);
        Log::info("schrijfAlleReviversCSVbestand: aantal uitvallers: $uitval" );

        Storage::disk('public')->put('LR_VoorraadRevivers_lijst.csv', $voorraden);
        Log::info("schrijfAlleReviversCSVbestand: aantal met laptop: $voorraad" );

        // Log::info("schrijfAlleReviversCSVbestand: ".count($this->uitvallerUsers) ."uitvallers: " . print_r($this->uitvallerUsers, true) );
    }

    public function schrijfAanvragersCSVbestand()
    {
        // Latitude is de Engelse term voor breedtegraad en longitude is de Engelse term voor lengtegraad

        Log::info('OpenStreetMap: schrijfAanvragersCSVbestand called');

        // $LR_OSM_lijst = Storage::disk('local')->get('LR_OSM_lijst.csv');
        // $arPostcode = str_getcsv($postcode,",");
        $kop = '"Afdeling","Username","Plaats","Postcode","Postal Code","latitude","longitude"';
        $regels = $kop."\n";
        $uitvallers = $kop."\n";
        $tel = 0;
        $uitval = 0;

        // doorloop users
        $aantal = count($this->aanvragerUsers);
        foreach ($this->aanvragerUsers as $aanvrager) {
            $regel  = '"'.$aanvrager['username'].'",';
            $regel .= '"'.$aanvrager['plaats'].'",';
            $regel .= '"'.substr($aanvrager['postcode'],0,4).'",';
            $regel .= '"'.$aanvrager['postcode'].'",';
            $regel .= $aanvrager['latitude'].",";
            $regel .= $aanvrager['longitude']."\n";
            if ($aanvrager['latitude'] > 0)  {
                $tel++;
                $regels .= '"'."Laptop Aanvragers".'",'.$regel;
            } else {
                $uitval++;
                $uitvallers .= '"'."Laptop Aanvragers met foute postcode".'",'.$regel;
            }
        }

        Storage::disk('public')->put('LR_Aanvragers_lijst.csv', $regels);
        Log::info("schrijfAanvragersCSVbestand: aantal aanvragers: $tel" );

        Storage::disk('public')->put('LR_FouteAanvragers_lijst.csv', $uitvallers);
        Log::info("schrijfAanvragersCSVbestand: aantal uitvallers: $uitval" );

        // Log::info("schrijfAlleReviversCSVbestand: ".count($this->uitvallerUsers) ."uitvallers: " . print_r($this->uitvallerUsers, true) );
    }



    public function handle(): void {
        Log::info('OpenStreetMap: job gestart '.date("Y-m-d H:i:s").'.');
        $this->verzamelAlleUsers();
        Log::info('OpenStreetMap: aantal verzamelde users: ' . count($this->reviverUsers));
        Log::info('OpenStreetMap: aantal verzamelde aanvragers: ' . count($this->aanvragerUsers));

        $this->verzamelPostcodeLijst();
        Log::info('OpenStreetMap: aantal verzamelde postcodes: ' . count($this->postcodeLijst));

        $this->verrijkReviversMetPositie();
        Log::info('OpenStreetMap: aantal verzamelde users na verrijkMetPositie: ' . count($this->reviverUsers));

        $this->verrijkAanvragersMetPositie();
        Log::info('OpenStreetMap: aantal verzamelde aanvragers na verrijkMetPositie: ' . count($this->aanvragerUsers));

        $this->verrijkMetGereserveerd();
        Log::info('OpenStreetMap: aantal verzamelde users na verrijkMetGereserveerd: ' . count($this->reviverUsers));
        //Log::info("'OpenStreetMap: alle users: " . print_r($this->reviverUsers, true) );

        $this->schrijfReviversCSVbestand();

        $this->schrijfAanvragersCSVbestand();

        Log::info('OpenStreetMap: job finished '.date("Y-m-d H:i:s").'.');
    }
}

