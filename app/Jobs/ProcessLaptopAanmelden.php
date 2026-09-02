<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\AanmeldingMail;
use App\Mail\AanmeldingMailError;

class ProcessLaptopAanmelden extends ProcessBaseJob
{
    // use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
        // Log::info('LaptopAanmelden: job created.');
    }

    // hier de code om de laptop aan te melden in SnipeIT, 
    // met behulp van de SnipeIT API. 
    // zie: https://snipe-it.readme.io/reference/api-overview
    //
    public function VerwerkAanmeldenInSnipeIT($laptop)
    {
        // Stap 1: zoek Company "Laptop Revive" in SnipeIT en haal het id op.
        $companyId = $this->verwerkSnipeITPart('companies', 'Laptop Revive', 1, [
            'name' => 'Laptop Revive',
            'notes' => 'Bedrijf aangemaakt door API'
        ]);

        // Stap 2: zoek Category "Laptops" in SnipeIT en haal het id op.
        $categoryId = $this->verwerkSnipeITPart('categories', 'Laptop', 1, [
            'name' => 'Laptop',
            'category_type' => 'Asset',
            'notes' => 'Categorie aangemaakt door API'
        ]);

        // Stap 3: zoek fabrikant in SnipeIT en haal het id op.
        $manufacturer = $laptop['manufacturer'] ?? 'Onbekende fabrikant';
        $manufacturer = ucfirst(strtolower($manufacturer)); // maak de fabrikantnaam consistent, met alleen de eerste letter in hoofdletter, om beter te kunnen matchen met bestaande fabrikanten in SnipeIT.
        $manufacturerId = $this->verwerkSnipeITPart('manufacturers', $manufacturer, 1, [
            'name' => $manufacturer,
            'notes' => 'Fabrikant aangemaakt door API'
        ]);

        // Stap 4: zoek Model productName in SnipeIT en haal het id op.
        $productName = $laptop['productname'] ?? 'Onbekend';
        $modelId = $this->verwerkSnipeITPart('models', $productName, 1, [
            'name' => $manufacturer . ' ' . $productName,
            'model_number' => $productName,
            'category_id' => $categoryId,
            'manufacturer_id' => $manufacturerId,
            'notes' => 'Model aangemaakt door API'
        ]);

        // Stap 5: zoek Status "Ingenomen" in SnipeIT en haal het id op.
        $statusLabelId = $this->verwerkSnipeITPart('statuslabels', 'Ingenomen', 1, [
            'name' => 'Ingenomen',
            'type' => 'pending',
            'notes' => 'Status aangemaakt door API'
        ]);

        // komt in notes te staan
        $userdisplayname = $laptop['userdisplayname'] ?? 'Onbekende user';

        // bepaal locatie = Plaats (Voornaam)
        $locationId = 0;
        $locationName = 'Onbekend';

        // Stap 6: opzoeken gebruiker met emailadres in SnipeIT en haal het id op. 
        // Als niet gevonden, maak foutmelding aan en zet $errorMsg in assetTag
        $userEmail = strtolower($laptop['email'] ?? '');
        $userId = $this->readSnipeITPartforId('users', $userEmail, 'email', 'asc', 'id');
        if ($userId === 0) {
            $errorMsg = "Gebruiker met emailadres " . $userEmail . " niet gevonden in SnipeIT";
            Log::error('LaptopAanmelden: ' . $errorMsg);
            $assetTag = "ERROR: " . $errorMsg;
        } else {
            // lees user by user_id tbv locatie
            $userData = $this->readSnipeITUserForId($userId);
            //Log::error('LaptopAanmelden gevonden user: ' . print_r($userData, true));

            // Stap 7: zoek Location, eerst van user daarna op "Voornaam.Woonplaats" in SnipeIT en haal het id op.
            $locationId = 0;
            if (isset($userData['location']['id'])) {
                $locationId = $userData['location']['id'];
                Log::info('LaptopAanmelden: SnipeIT user location id found: ' . $locationId);
            } else {
                if (isset($userData['city'])) {
                    $firstName = ucfirst(strtolower($userData['first_name']));
                    $city = ucfirst(strtolower($userData['city']));
                    $city = str_replace("'", '', $city); // ' van 's Gravenhage

                    $locationName = preg_replace('/\s+/','',$firstName.".".$city); // [voornaam].[plaats]
                    $locationId = $this->readSnipeITPartforId('locations', $locationName, '', '', 'id');
                    // kan 0 geven
                }
            }

            // Stap 8: zoek laatste asset tag in SnipeIT en bepaal de volgende asset tag.   
            $assetTag = 'LR';
            $latestAssetTag = $this->readSnipeITPartforId('hardware', $assetTag, 'asset_tag', 'desc', 'asset_tag');
            if (substr($latestAssetTag, 0, 2) !== 'LR') {
                Log::error('LaptopAanmelden: SnipeIT no asset tags found starting with "LR" ');
                $latestAssetTag = 'LR00001';
            } else {
                Log::info('LaptopAanmelden: SnipeIT latest asset tag found: ' . $latestAssetTag);
            }
            $nummer = intval(substr($latestAssetTag, 2)) + 1;
            $assetTag = 'LR' . str_pad($nummer, 5, '0', STR_PAD_LEFT);

            // Stap 9: maak een nieuwe asset aan in SnipeIT met de gegevens van de laptop, en de opgehaalde ids van company, category, model, location, en de nieuwe asset tag.
            $hardwareArray = [
                "archived" => false,
                "warranty_months" => null,
                "depreciate" => false,
                "supplier_id" => null,
                "requestable" => false,
                "asset_tag" => $assetTag,
                "status_id" => $statusLabelId,
                "model_id" => $modelId,
                "serial" => $laptop['serialnumber'] ?? 'Onbekend',
                "company_id" => $companyId,
                "notes" => "Asset aangemaakt door: $userdisplayname"
            ];

            if ($locationId > 0) {
                $hardwareArray['location_id'] = $locationId;
                $hardwareArray['rtd_location_id'] = $hardwareArray['location_id'];
            } else {
                $hardwareArray["notes"] .="\nOnbekende locatie $locationName";
            }

            $assetId = $this->createSnipeITPart('hardware', $hardwareArray);

            // als aanmaken niet lukt dan foutmelding in assetTag zetten.
            if (!$assetId) {
                $errorMsg = "Fout bij aanmaken asset in SnipeIT";
                $assetTag = "ERROR: " . $errorMsg;
            }

        }

        if (substr($assetTag, 0, 5) === "ERROR") {
            $laptop['lrnummer'] = "Onbekend";
            $laptop['error'] = substr($assetTag, 7);
            // $laptop['cc'] =  'CC gestuurd naar info@laptoprevive.nl';
            Mail::to($laptop['email'])->send(new AanmeldingMailError($laptop));
            $mailhost = env('MAIL_HOST');
            Log::info('LaptopAanmelden: gevonden mailhost: -' . $mailhost. '-');
            if ($mailhost == 'sandbox.smtp.mailtrap.io') {
                Log::info('LaptopAanmelden: Mailtrap detected, waiting 15 seconds after sending');
                sleep(15); // wacht 15 seconden voor mailtrap
            }
            /*
            $laptop['cc'] =  'CC voor info@laptoprevive.nl';   
            Mail::to('info@laptoprevive.nl')->send(new AanmeldingMailError($laptop));
            Log::info('LaptopAanmelden: SnipeIT asset not created, error: ' . $laptop['error']);
            if ($mailhost == 'sandbox.smtp.mailtrap.io') {
                Log::info('LaptopAanmelden: Mailtrap detected, waiting 15 seconds after sending');
                sleep(15); // wacht 15 seconden voor mailtrap
            }
            */
        } else {
            $laptop['lrnummer'] = $assetTag;
            $laptop['error'] = "";
            Mail::to($laptop['email'])->send(new AanmeldingMail($laptop));
            Log::info('LaptopAanmelden: SnipeIT asset created with asset tag: ' . $assetTag);
            $mailhost = env('MAIL_HOST');
            Log::info('LaptopAanmelden: gevonden mailhost: -' . $mailhost. '-');
            if ($mailhost == 'sandbox.smtp.mailtrap.io') {
                Log::info('LaptopAanmelden: Mailtrap detected, waiting 15 seconds after sending');
                sleep(15); // wacht 15 seconden voor mailtrap
            }
        }

    }

    public function handle(): void {
        Log::info('LaptopAanmelden: job gestart '.date("Y-m-d H:i:s").'.');
        $laptops = $this->readNextCloud('aanmelden');
        Log::info('LaptopAanmelden: $laptops: ' . count($laptops));

        foreach ($laptops as $laptop) {
            Log::info('LaptopAanmelden: laptop: ' . print_r($laptop, true) );
            $this->VerwerkAanmeldenInSnipeIT($laptop);

            //$lrNummer = $this->VerwerkInSnipeIT($laptop);
            //$laptop['lrnummer'] = $lrNummer;
            //Mail::to($laptop['email'])->send(new AanmeldingMail($laptop));
        }   
        Log::info('LaptopAanmelden: job finished '.date("Y-m-d H:i:s").'.');
    }
}

