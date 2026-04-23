<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use App\Mail\AanmeldingMail;
use PhpParser\Node\Expr\Print_;

class ProcessLaptopAanmelden implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
        // Log::info('LaptopAanmelden: job created.');
    }

    /**
     * Read data from Nextcloud Forms.
     * $filePath determines what data to read:
     *   $filePath: "" => forms lezen
     *   $filePath: "/1/questions" => questions lezen
     *   $filePath: "/1/submissions" => submissions lezen
     * $filePath: "/1/submissions/1" => submission 1 lezen
     */
    private function readNextcloudForms($filePath)
    {
        $url = env('LR_NEXTCLOUD_URL') . '/ocs/v2.php/apps/forms/api/v3/forms' . $filePath;
        $username = env('LR_NEXTCLOUD_USERNAME');
        $password = env('LR_NEXTCLOUD_PASSWORD');

        $response = Http::withHeaders([
            'OCS-APIREQUEST' => 'true',
            'Accept' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
        ])->get($url);

        $ocs_data = [];
        if ($response->successful()) {
            $ocs_response = $response->body();
            $ocs = json_decode($ocs_response, true);
            $ocs_status = $ocs['ocs']['meta']['status'] ?? 'unknown';
            Log::info('LaptopAanmelden: Nextcloud response status: ' . $ocs_status);
            if ($ocs_status === 'ok') {
                $ocs_data = $ocs['ocs']['data'] ?? [];
            }
        } else {
            Log::error('LaptopAanmelden: Nextcloud Failed to read: ' . $response->status());
            return $ocs_data;
        }
        return $ocs_data;
    }

    private function deleteNextcloudSubmissions($formId)
    {
        $url = env('LR_NEXTCLOUD_URL') . '/ocs/v2.php/apps/forms/api/v3/forms/' . $formId . '/submissions';
        $username = env('LR_NEXTCLOUD_USERNAME');
        $password = env('LR_NEXTCLOUD_PASSWORD');

        $response = Http::withHeaders([
            'OCS-APIREQUEST' => 'true',
            'Accept' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
        ])->delete($url);

        if ($response->successful()) {
            Log::info('LaptopAanmelden: Nextcloud submissions deleted: formId=' . $formId . '.');
        } else {
            Log::error('LaptopAanmelden: Nextcloud Failed to delete submission: formId=' . $formId . '.' . ' Status: ' . $response->status());
        }
    }
    /**
     * Read forms, questions and submissions from Nextcloud Forms.
     */
    private function readNextCloud()
    {
        //
        $results = [];
        $forms = $this->readNextcloudForms('');
        Log::info('LaptopAanmelden: forms gelezen: ' . count($forms));
        $formGevonden = false;
        foreach ($forms as $form) {
            Log::info('LaptopAanmelden: form: ' . $form['id'] . ' -' . $form['title'].'-');
            if ($form['title'] === 'Laptop aanmelden') {
                $formGevonden = true;
                Log::info('LaptopAanmelden: form gevonden: ' . $form['id'] . ' - ' . $form['title']);
                $questions = $this->readNextcloudForms('/' . $form['id'] . '/questions');
                Log::info('LaptopAanmelden: questions gelezen: ' . count($questions));
                foreach ($questions as $question) {
                    Log::info('LaptopAanmelden: question: ' . $question['id'] . ' - ' . $question['text']);
                }
                $submissions_data = $this->readNextcloudForms('/' . $form['id'] . '/submissions');
                $submissions = $submissions_data['submissions'] ?? [];
                $submissionGevonden = false;
                Log::info('LaptopAanmelden: submissions gelezen: ' . count($submissions));
                foreach ($submissions as $submission) {
                    $submissionGevonden = true;
                    Log::info('LaptopAanmelden: submission: ' . $submission['id'] . ' - ' . $submission['userDisplayName']);
                    $submissionDetails = $this->readNextcloudForms('/' . $form['id'] . '/submissions/' . $submission['id']);
                    //Log::info('LaptopAanmelden: submission details: ' . json_encode($submissionDetails));
                    $result = [];
                    $result["submissionid"] = $submission['id'];
                    $answers = $submissionDetails['answers'] ?? [];
                    foreach ($answers as $answer) {
                        Log::info('LaptopAanmelden: answer: ' . $answer['questionId'] . ' - ' . $answer['text']);
                        foreach ($questions as $question) {
                            if ($question['id'] === $answer['questionId']) {
                                $key = str_replace(' ', '', strtolower($question['text']));
                                $result[$key] = $answer['text'];    
                            }
                            // Log::info('LaptopAanmelden: question: ' . $question['id'] . ' - ' . $question['text']);
                        }
                    }
                    $results[] = $result;
                } // for each submissions 

                if (!$submissionGevonden) {
                    Log::info('LaptopAanmelden: geen submissions gevonden voor form "Laptop aanmelden".');
                } else {
                    // delete all submissions after processing, to avoid processing the same submissions again in the next run. 
                    $this->deleteNextcloudSubmissions($form['id']);
                }

            } // form is Laptop aanmelden

        } // foreach forms  

        if (!$formGevonden) {
            Log::warning('LaptopAanmelden: form "Laptop aanmelden" niet gevonden.');
        }

        if (count($results) === 0) {
            Log::info('LaptopAanmelden: geen laptops gevonden.');
        } else {
            Log::info('LaptopAanmelden: laptops gevonden: ' . count($results));
        }

        return $results;
    }

    // lezen in SnipeIT van een bepaald part is: 
    // part kan zijn: "companies", "models", "locations", etc. 
    private function readSnipeITPartforId($part, $search = '', $sort = '', $order = 'asc', $field = 'id')
    {
        //
        $url = env('SNIPEIT_URL') . '/api/v1/' . $part .'?limit=50&offset=0';
        if  ($search !== '') {
            $url .= '&search=' . urlencode($search);
        }
        if  ($sort !== '') {
            $url .= '&sort=' . urlencode($sort);
            if  ($order !== '') {
                $url .= '&order=' . urlencode($order);
            }
        }
        Log::info('LaptopAanmelden: SnipeIT url ' . $url . '. ');

        $token = env('SNIPEIT_TOKEN');

        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ])->get($url);

        $data = [];
        if ($response->successful()) {
            $responseData = $response->json();
            $data = $responseData['rows'][0] ?? [];
            Log::info('LaptopAanmelden: SnipeIT response for ' . $part . ': ' . count($data) . ' items.');
            //Log::info('LaptopAanmelden: SnipeIT response data for ' . $part . ': ' . print_r($responseData, true) );
        } else {
            Log::error('LaptopAanmelden: SnipeIT Failed to read ' . $part . ': ' . $response->status());
        }    

        if (count($data) !== 0) {
            $id = $data[$field] ?? 0;
            Log::info('LaptopAanmelden: SnipeIT ' . $field .'='. $id .' found for ' . $part . '.');
        } else {
            $id = ($field === 'id') ? 0 : 'LR00000'; // default value if not found, depending on the field type
            Log::info('LaptopAanmelden: SnipeIT no ' . $field . ' found for ' . $part . '. ');
        }       
        return $id;
    }

    // aanmaken in SnipeIT van een bepaald part, 
    // met behulp van de SnipeIT API.
    private function createSnipeITPart($part, $data): void
    {
        $url = env('SNIPEIT_URL') . '/api/v1/' . $part ;
        $token = env('SNIPEIT_TOKEN');

        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        ])->post($url, $data);

        if ($response->successful()) {
            //$responseData = $response->json();
            Log::info('LaptopAanmelden: SnipeIT created ' . $part );
        } else {
            Log::error('LaptopAanmelden: SnipeIT Failed to create ' . $part . ': ' . $response->status() );
        }

    }

     // zoek part in SnipeIT en haal het id op. 
     // Als niet gevonden, maak de part aan in SnipeIT en haal daarna het id op. 
     // Als nog steeds niet gevonden, gebruik default id. Log alle stappen.
    private function verwerkSnipeITPart($part, $search, $defaultId, $data)
    {
        // zoek part in SnipeIT 
        $partId = $this->readSnipeITPartforId($part, $search, 'name', 'asc');
        if ($partId === 0) {
            //Log::error('LaptopAanmelden: SnipeIT ' . $part . ' " '.$search. '" not found. ');
            $this->createSnipeITPart($part, $data);
            // opnieuw zoeken
            $partId = $this->readSnipeITPartforId($part, $search, 'name', 'asc');
        }
        if ($partId === 0) {
            $partId = $defaultId; // default part id, if creation of failed.
            Log::error('LaptopAanmelden: SnipeIT ' . $part . ' "'.$search. '" not found after creation attempt. Using default ' . $part . ' id: ' . $partId);
        } else {
            Log::info('LaptopAanmelden: SnipeIT ' . $part . ' "'.$search. '" has id: ' . $partId);
        }                    
        return $partId;
    }

    // hier de code om de laptop aan te melden in SnipeIT, 
    // met behulp van de SnipeIT API. 
    // zie: https://snipe-it.readme.io/reference/api-overview
    //
    private function VerwerkInSnipeIT($laptop)
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
        $manufacturerId = $this->verwerkSnipeITPart('manufacturers', $manufacturer, 1, [
            'name' => $manufacturer,
            'notes' => 'Fabrikant aangemaakt door API'
        ]);

        // Stap 4: zoek Model productName in SnipeIT en haal het id op.
        $productName = $laptop['productname'] ?? 'Onbekend model';
        $modelId = $this->verwerkSnipeITPart('models', $productName, 1, [
            'name' => $productName,
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

        // Stap 6: zoek Location "plaats (naam)" in SnipeIT en haal het id op.
        $locationName = strtolower($laptop['naam_plaats']) ?? 'naam_onbekend';
        $locationName = str_replace('-', '_', $locationName); // vervang - door underscores
        $delen = explode('_', $locationName,2);
        if (count($delen) == 1) {
            $locationName = ucfirst(trim($delen[0])); // neem alleen het eerste deel van de locatie, voor de naam in SnipeIT
        }
        if (count($delen) == 2) {
            $locationName = ucfirst(trim($delen[1])).' ('.ucfirst(trim($delen[0])).')'; // neem alleen het eerste deel van de locatie, voor de naam in SnipeIT
        }
        $locationId = $this->verwerkSnipeITPart('locations', $locationName, 1, [
            'name' => $locationName,
            'company_id' => $companyId,
            'notes' => 'Locatie aangemaakt door API'
        ]);

        // Stap 7: zoek laatste asset tag in SnipeIT en bepaal de volgende asset tag.   
        $assetTag = 'LR0';
        $latestAssetTag = $this->readSnipeITPartforId('hardware', $assetTag, 'asset_tag', 'desc', 'asset_tag');
        if (substr($latestAssetTag, 0, 2) !== 'LR') {
            Log::error('LaptopAanmelden: SnipeIT no asset tags found starting with "LR" ');
            $latestAssetTag = 'LR00001';
        } else {
            Log::info('LaptopAanmelden: SnipeIT latest asset tag found: ' . $latestAssetTag);
        }
        $nummer = intval(substr($latestAssetTag, 3)) + 1;
        $assetTag = 'LR' . str_pad($nummer, 5, '0', STR_PAD_LEFT);

        // Stap 8: maak een nieuwe asset aan in SnipeIT met de gegevens van de laptop, en de opgehaalde ids van company, category, model, location, en de nieuwe asset tag.
        $this->createSnipeITPart('hardware', 
        [
            "archived" => false,
            "warranty_months" => null,
            "depreciate" => false,
            "supplier_id" => null,
            "requestable" => false,
            "rtd_location_id" => $locationId,
            "location_id" => $locationId,
            "asset_tag" => $assetTag,
            "status_id" => $statusLabelId,
            "model_id" => $modelId,
            "serial" => $laptop['serialnumber'] ?? 'Onbekend',
            "company_id" => $companyId,
            "notes" => "Asset aangemaakt door API"
        ]);

        return $assetTag;

    }

    public function handle(): void {

        Log::info('LaptopAanmelden: job gestart '.date("Y-m-d H:i:s").'.');
        $laptops = $this->readNextCloud();
        Log::info('LaptopAanmelden: $laptops: ' . count($laptops));
        foreach ($laptops as $laptop) {
            Log::info('LaptopAanmelden: laptop: ' . print_r($laptop, true) );
            $lrNummer = $this->VerwerkInSnipeIT($laptop);
            $laptop['lrnummer'] = $lrNummer;
            Mail::to($laptop['email'])->send(new AanmeldingMail($laptop));
        }   
        Log::info('LaptopAanmelden: job finished '.date("Y-m-d H:i:s").'.');
    }
}

