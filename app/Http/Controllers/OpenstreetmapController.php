<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class OpenstreetmapController extends Controller
{

    public function index(Request $request)
    {
        echo "<h1>OpenstreetmapController</h1>";
        echo "stap 1: Get Authorization code, call /openstreetmap/getAuthorizationUrl<br>";
        echo "stap 2: Get Access Token, call /openstreetmap/getAccessToken?code=AUTHORIZATION_CODE<br>";
        echo "stap 3: Test Access Token, call /openstreetmap/testAccessToken<br>";

        //return "Welcome to the index page of OpenstreetmapController";
    }

    public function getAuthorizationUrl(Request $request)
    {
            // Options are optional, defaults to 'read_prefs' only
        $options = ['scope' => 'read_prefs write_api'];

        // Get authorization URL via JBelien\OAuth2\Client\Provider\OpenStreetMap
        $OpenStreetMapProvider = new \JBelien\OAuth2\Client\Provider\OpenStreetMap([
                'clientId'     => env('OPENSTREETMAP_API_CLIENT_ID', 'your-client-id'),          // The client ID assigned to you by OpenStreetMap.org
                'clientSecret' => env('OPENSTREETMAP_API_CLIENT_SECRET', 'your-client-secret'),      // The client password assigned to you by OpenStreetMap.org
                'redirectUri'  => env('OPENSTREETMAP_API_REDIRECT_URI', 'http://your-redirect-uri'), // The return URL you specified for your app on OpenStreetMap.org
                'dev'          => env('OPENSTREETMAP_API_DEV', false)              // Whether to use the OpenStreetMap test environment at https://master.apis.dev.openstreetmap.org/
        ]);

        // Get the authorization URL and state
        $authorizationUrl = $OpenStreetMapProvider->getAuthorizationUrl($options);
        $oauth2state = $OpenStreetMapProvider->getState();
        //$optionProvider = self::$OpenStreetMapProvider->getOptionProvider();
        //$redirectUri = self::$OpenStreetMapProvider->getOptionProvider()->getRedirectUri();

        // Get state and store it to the session
        // $_SESSION['oauth2state'] = $OpenStreetMapProvider->getState();
        Log::info('OpenStreetMapController: oauth2state: ' . $oauth2state);
        Log::info('OpenStreetMapController: authorizationUrl: ' . $authorizationUrl);

        echo "Authorization URL: <pre>" . $authorizationUrl . "</pre>\n";
        
        // TODO: Redirect the user to the authorization URL
        header('Location: ' . $authorizationUrl );
        exit;
        
    }      


    public function getAccessToken(Request $request)
    {
        Log::info('OpenStreetMapController: getAccessToken called');
        Log::info('OpenStreetMapController: getAccessToken request: ' . print_r($request->all(), true));

        //exit("Callback request: " . print_r($request->all(), true));
        $authCode = $request->input('code');
        if (!$authCode) {
            Log::error('OpenStreetMapController: getAccessToken No authorization code received');
            return "No authorization code received. Please check the url.";
        }

        //$authCode = env('OPENSTREETMAP_API_AUTHORIZATION_CODE');
        Log::info('OpenStreetMapController: getAccessToken authCode: ' . $authCode);

        // Wissel de opgevangen authcode in voor het definitieve Access Token via een POST-request
        $tokenParams = [
            'grant_type'    => 'authorization_code',
            'code'          => $authCode,
            'redirect_uri'  => env('OPENSTREETMAP_API_REDIRECT_URI', 'http://your-redirect-uri'),
            'client_id'     => env('OPENSTREETMAP_API_CLIENT_ID', 'your-client-id'),
            'client_secret' => env('OPENSTREETMAP_API_CLIENT_SECRET', 'your-client-secret'),
        ];
        $urlParams = http_build_query($tokenParams);
        $tokenEndpoint = env('OPENSTREETMAP_API_URI', 'https://api.openstreetmap.org/') . 'oauth2/token';
        Log::info('OpenStreetMapController: getAccessToken tokenEndpoint: ' . $tokenEndpoint);
        Log::info('OpenStreetMapController: getAccessToken tokenParams: ' . print_r($tokenParams, true));
        Log::info('OpenStreetMapController: getAccessToken urlParams: ' . print_r($urlParams, true));

        // $response = Http::post($tokenEndpoint, $tokenParams);
        $response = Http::asForm()->withHeaders([
            'User-Agent' => 'MijnOSMApp/1.0',
            'Content-Type' => 'application/x-www-form-urlencoded'
            ])->post($tokenEndpoint, $tokenParams);
        //Log::info('OpenStreetMapController: getAccessToken response: ' . print_r($response,true));
        
        $responseData = $response->json();
        Log::info('OpenStreetMapController: getAccessToken responseData: ' . print_r($responseData, true));

        //$tokenData = json_decode($responseData, true);
        //Log::info('OpenStreetMapController: getAccessToken tokenData: ' . print_r($tokenData, true));
        
        if (isset($responseData['access_token'])) {
            Log::info('OpenStreetMapController: getAccessToken access_token: ' . $responseData['access_token']);
            echo "Access Token: " . $responseData['access_token'] . "<br>\n";
            // Write the access token to a local file for later use
            Storage::disk('local')->put('openstreetmap_access_token.json', json_encode($responseData));

        } else if (isset($responseData['error'])) {
            Log::error('OpenStreetMapController: getAccessToken Error occurred while fetching token');
            Log::error('OpenStreetMapController: getAccessToken error: ' . $responseData['error']);
            Log::error('OpenStreetMapController: getAccessToken error_description: ' . $responseData['error_description']);
            echo "error_description: " . $responseData['error_description'] . "<br>\n";
        } 
        else 
            {
            Log::error('OpenStreetMapController: getAccessToken Error occurred while fetching token');
            Log::error('OpenStreetMapController: getAccessToken responseData: ' . print_r($responseData, true));
            echo "Error occurred while fetching token. Response: " . print_r($responseData, true) . "<br>\n";
        }

        return "End of the getAccessToken Controller method";
        // Handle the callback from OpenStreetMap API
        //$code = $request->input('code');
        // You can exchange the code for an access token here
        // return "Received code: " . $code;
    }
    
    
    public function testAccessToken(Request $request)
    {
        Log::info('OpenStreetMapController: testAccessToken called');
        Log::info('OpenStreetMapController: testAccessToken request: ' . print_r($request->all(), true));

        //$accessToken = env('OPENSTREETMAP_API_ACCESS_TOKEN');

        $accessTokenFromFile = Storage::disk('local')->get('openstreetmap_access_token.json');
        if ($accessTokenFromFile) {
            $accessTokenData = json_decode($accessTokenFromFile, true);
            if (isset($accessTokenData['access_token'])) {
                $accessToken = $accessTokenData['access_token'];
            }
        }

        Log::info('OpenStreetMapController: testAccessToken accessToken: ' . $accessToken);

        $urlEndpoint = env('OPENSTREETMAP_API_URI', 'https://api.openstreetmap.org/');  

        // $response = Http::post($tokenEndpoint, $tokenParams);
        $userResponse = Http::withHeaders([
            'Authorization' => "Bearer {$accessToken}",
            'User-Agent' => 'MijnOSMApp/1.0',
            'Accept' => 'application/json'
        ])->get($urlEndpoint . 'api/0.6/user/details');

        //$userData = json_decode($userResponse, true);
        $userData = $userResponse->json();

        if (isset($userData['error'])) {    
            Log::error('OpenStreetMapController: testAccessToken Error occurred while fetching user details');
            Log::error('OpenStreetMapController: testAccessToken error: ' . $userData['error']);
            Log::error('OpenStreetMapController: testAccessToken error_description: ' . $userData['error_description']);
            echo "<h1>Fout bij testen Access Token!</h1>";
            echo "error: " . $userData['error'];
            echo "error_description: " . $userData['error_description'] . "<br>\n";
        } 
        else 
        {
            Log::info('OpenStreetMapController: testAccessToken userData: ' . print_r($userData,true));
            echo "<h1>Succesvol getest!</h1>";
            echo "<pre>";
            //echo "Userresponse:<br>";
            //print_r($userResponse);
            //echo "<br>";
            echo "Userdata:<br>";
            print_r($userData);
            echo "<br>";
            echo "</pre>";
            echo "Wanneer u boven deze regel geen userdata ziet, dan is er iets mis met het access token. U kunt de procedure vanaf stap 1 een keer herhalen. Lukt het niet neem dan contact op met de programmuer).<br>"; 
            
        }

    }
}
