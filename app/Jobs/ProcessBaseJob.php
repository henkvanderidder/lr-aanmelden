<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class ProcessBaseJob implements ShouldQueue, ShouldBeUnique
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
    protected function readNextcloudForms($filePath)
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

    protected function deleteNextcloudSubmissions($formId)
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
    protected function readNextCloud($soort = 'aanmelden')
    {
        //
        $results = [];
        $forms = $this->readNextcloudForms('');
        Log::info('Laptop' . ucfirst($soort) . ': forms gelezen: ' . count($forms));
        $formGevonden = false;
        foreach ($forms as $form) {
            Log::info('Laptop' . ucfirst($soort) . ': form: ' . $form['id'] . ' -' . $form['title'].'-');
            if ($form['title'] === 'Laptop ' . ucfirst($soort)) {
                $formGevonden = true;
                Log::info('Laptop' . ucfirst($soort) . ': form gevonden: ' . $form['id'] . ' - ' . $form['title']);
                $questions = $this->readNextcloudForms('/' . $form['id'] . '/questions');
                Log::info('Laptop' . ucfirst($soort) . ': questions gelezen: ' . count($questions));
                foreach ($questions as $question) {
                    Log::info('Laptop' . ucfirst($soort) . ': question: ' . $question['id'] . ' - ' . $question['text']);
                }
                $submissions_data = $this->readNextcloudForms('/' . $form['id'] . '/submissions');
                $submissions = $submissions_data['submissions'] ?? [];
                $submissionGevonden = false;
                Log::info('Laptop' . ucfirst($soort) . ': submissions gelezen: ' . count($submissions));
                foreach ($submissions as $submission) {
                    $submissionGevonden = true;
                    Log::info('Laptop' . ucfirst($soort) . ': submission: ' . $submission['id'] . ' - ' . $submission['userDisplayName']);
                    $submissionDetails = $this->readNextcloudForms('/' . $form['id'] . '/submissions/' . $submission['id']);
                    //Log::info('Laptop' . ucfirst($soort) . ': submission details: ' . json_encode($submissionDetails));
                    $result = [];
                    $result["submissionid"] = $submission['id'];
                    $answers = $submissionDetails['answers'] ?? [];
                    foreach ($answers as $answer) {
                        Log::info('Laptop' . ucfirst($soort) . ': answer: ' . $answer['questionId'] . ' - ' . $answer['text']);
                        foreach ($questions as $question) {
                            if ($question['id'] === $answer['questionId']) {
                                $key = str_replace(' ', '', strtolower($question['text']));
                                $result[$key] = $answer['text'];    
                            }
                            // Log::info('Laptop' . ucfirst($soort) . ': question: ' . $question['id'] . ' - ' . $question['text']);
                        }
                    }
                    $results[] = $result;
                } // for each submissions 

                if (!$submissionGevonden) {
                    Log::info('Laptop' . ucfirst($soort) . ': geen submissions gevonden voor form "Laptop ' . ucfirst($soort) . '".');
                } else {
                    // delete all submissions after processing, to avoid processing the same submissions again in the next run. 
                    $this->deleteNextcloudSubmissions($form['id']);
                }

            } // form is Laptop aanmelden

        } // foreach forms  

        if (!$formGevonden) {
            Log::warning('Laptop' . ucfirst($soort) . ': form "Laptop ' . ucfirst($soort) . '" niet gevonden.');
        }

        if (count($results) === 0) {
            Log::info('Laptop' . ucfirst($soort) . ': geen formulieren gevonden.');
        } else {
            Log::info('Laptop' . ucfirst($soort) . ': formulieren gevonden: ' . count($results));
        }

        return $results;
    }

    // lezen in SnipeIT van een bepaald part is: 
    // part kan zijn: "companies", "models", "locations", etc. 
    protected function readSnipeITPartforId($part, $search = '', $sort = '', $order = 'asc', $field = 'id')
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
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
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
    protected function createSnipeITPart($part, $data): string
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
            return "OK";
        } else {
            Log::error('LaptopAanmelden: SnipeIT Failed to create ' . $part . ': ' . $response->status() );
            return "FAILED";
        }

    }

    // update in SnipeIT van een bepaald part, 
    // met behulp van de SnipeIT API.
    protected function updateSnipeITPart($part, $id, $data): string
    {
        $url = env('SNIPEIT_URL') . '/api/v1/' . $part . '/' . $id;
        Log::info('LaptopAanmelden: SnipeIT update url: ' . $url);
        $token = env('SNIPEIT_TOKEN');

        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        ])->put($url, $data);

        if ($response->successful()) {
            //$responseData = $response->json();
            Log::info('LaptopAanmelden: SnipeIT updated ' . $part. ' for ID ' . $id. ' with data: ' . print_r($data, true) );
            return "OK";
        } else {
            Log::error('LaptopAanmelden: SnipeIT Failed to update ' . $part . ' for ID ' . $id . ': ' . $response->status() );
            return "FAILED";
        }

    }


     // zoek part in SnipeIT en haal het id op. 
     // Als niet gevonden, maak de part aan in SnipeIT en haal daarna het id op. 
     // Als nog steeds niet gevonden, gebruik default id. Log alle stappen.
    protected function verwerkSnipeITPart($part, $search, $defaultId, $data): int
    {

        $errorMsg = "";
        
        // zoek part in SnipeIT 
        $partId = $this->readSnipeITPartforId($part, $search, 'name', 'asc');
        if ($partId === 0) {
            //Log::error('LaptopAanmelden: SnipeIT ' . $part . ' " '.$search. '" not found. ');
            $result =$this->createSnipeITPart($part, $data);
            if ($result === "OK") {
                Log::info('LaptopAanmelden: SnipeIT ' . $part . ' "'.$search. '" created successfully. ');
                // opnieuw zoeken
                $partId = $this->readSnipeITPartforId($part, $search, 'name', 'asc');
            } else {
                $errorMsg = "Mislukt om " . $part . ' "' . $search . '" aan te maken in SnipeIT';
                Log::error('LaptopAanmelden: ' . $errorMsg);
            }
        }
        if ($partId === 0) {
            if ($defaultId !== 0) {
                $partId = $defaultId; // default part id, if creation of failed.
                Log::info('LaptopAanmelden: SnipeIT ' . $part . ' "'.$search. '" not found after creation attempt. Using default ' . $part . ' id: ' . $partId);
            } else {
                Log::error('LaptopAanmelden: SnipeIT ' . $part . ' "'.$search. '" not found and creation failed, and no default id provided. ');
                $partId = -1;
            }
        } else {
            Log::info('LaptopAanmelden: SnipeIT ' . $part . ' "'.$search. '" has id: ' . $partId);
        }                    
        return $partId;
    }


}

