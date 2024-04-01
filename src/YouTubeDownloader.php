<?php

namespace Sorshion\YouTube;

use Sorshion\YouTube\Exception\TooManyRequestsException;
use Sorshion\YouTube\Exception\VideoNotFoundException;
use Sorshion\YouTube\Exception\VideoPlayerApiException;
use Sorshion\YouTube\Exception\YouTubeException;
use Sorshion\YouTube\Models\VideoInfo;
use Sorshion\YouTube\Models\YouTubeCaption;
use Sorshion\YouTube\Models\YouTubeConfigData;
use Sorshion\YouTube\Responses\PlayerApiResponse;
use Sorshion\YouTube\Responses\VideoPlayerJs;
use Sorshion\YouTube\Responses\WatchVideoPage;
use Sorshion\YouTube\Utils\Utils;

class YouTubeDownloader
{
    protected Browser $client;

    private $defaultClient = 'android';

    private $clientBodyConfig = [
        'android' => [
            'context' => [
                'client' => [
                    'clientName' => 'ANDROID',
                    'clientVersion' => '19.09.37',
                    'androidSdkVersion' => 30,
                    'userAgent' => 'com.google.android.youtube/19.09.37(Linux; U; Android 11) gzip'
                ],
            ],
            'params' => 'CgIIAQ=='
           
        ],
        'ios' => [
            'context' => [
                'client' => [
                    'clientName' => 'IOS',
                    'clientVersion' => '19.09.3',
                    'deviceModel'=> 'iPhone14,3',
                    'userAgent' => 'com.google.ios.youtube/19.09.3 (iPhone14,3; U; CPU iOS 15_6 like Mac OS X)'
                ],
            ],
        ]
    ];

    public function __construct()
    {
        $this->client = new Browser();
    }

    // Specify network options to be used in all network requests
    public function getBrowser(): Browser
    {
        return $this->client;
    }

    public function setDefaultClient($defaultClient)
    {
        $this->defaultClient = $defaultClient;
    }

    /**
     * @param string $query
     * @return array
     */
    public function getSearchSuggestions(string $query): array
    {
        $query = rawurlencode($query);

        $response = $this->client->get('http://suggestqueries.google.com/complete/search', [
            'client' => 'firefox',
            'ds' => 'yt',
            'q' => $query
        ]);
        $json = json_decode($response->body, true);

        if (is_array($json) && count($json) >= 2) {
            return $json[1];
        }

        return [];
    }

    public function getVideoInfo(string $videoId): ?VideoInfo
    {
        $page = $this->getPage($videoId);
        return $page->getVideoInfo();
    }

    public function getPage(string $url): WatchVideoPage
    {
        $video_id = Utils::extractVideoId($url);

        // exact params as used by youtube-dl... must be there for a reason
        $response = $this->client->get("https://www.youtube.com/watch?" . http_build_query([
                'v' => $video_id,
                'gl' => 'US',
                'hl' => 'en',
                'has_verified' => 1,
                'bpctr' => 9999999999
            ]));

        return new WatchVideoPage($response);
    }

    // Downloading android player API JSON
    protected function getPlayerApiResponse(string $video_id, YouTubeConfigData $configData): PlayerApiResponse
    {
        $clientBodyConfig = $this->clientBodyConfig[$this->defaultClient];
        $defaultBody = [
            'videoId' => $video_id,
            'playbackContext' => [
                'contentPlaybackContext' => [
                    'html5Preference' => 'HTML5_PREF_WANTS'
                ]
            ],
            'racyCheckOk' => true,
            'contentCheckOk' => True,
            'context' => [
                'client' => [
                    'hl' => 'en',
                    'timeZone' => 'UTC',
                    'utcOffsetMinutes' => 0,
                ],
            ],
        ];

        $body = array_merge_recursive($defaultBody, $clientBodyConfig);

        $header = [
            'Content-Type' => 'application/json',
            'Origin' => 'https://www.youtube.com',
            'X-Goog-Visitor-Id' => $configData->getGoogleVisitorId(),
            'X-Youtube-Client-Name' => $configData->getClientName(),
            'X-Youtube-Client-Version' => $configData->getClientVersion(),
            'User-Agent'=> $clientBodyConfig['context']['client']['userAgent'],
        ];

        // exact params matter, because otherwise "slow" download links will be returned
        $response = $this->client->post("https://www.youtube.com/youtubei/v1/player?key=" . $configData->getApiKey(), json_encode($body), $header);

        return new PlayerApiResponse($response);
    }

    /**
     *
     * @param string $video_id
     * @param array $extra
     * @return DownloadOptions
     * @throws TooManyRequestsException
     * @throws VideoNotFoundException
     * @throws YouTubeException
     */
    public function getDownloadLinks(string $video_id, array $extra = []): DownloadOptions
    {
        $video_id = Utils::extractVideoId($video_id);

        if (!$video_id) {
            throw new \InvalidArgumentException("Invalid Video ID: " . $video_id);
        }

        $page = $this->getPage($video_id);

        if ($page->isTooManyRequests()) {
            throw new TooManyRequestsException($page);
        } elseif (!$page->isStatusOkay()) {
            throw new YouTubeException('Page failed to load. HTTP error: ' . $page->getResponse()->error);
        } elseif ($page->isVideoNotFound()) {
            throw new VideoNotFoundException('Video Not Found!');
        } elseif ($page->getPlayerResponse()->getPlayabilityStatusReason()) {
            throw new YouTubeException($page->getPlayerResponse()->getPlayabilityStatusReason());
        }

        // a giant JSON object holding useful data
        $youtube_config_data = $page->getYouTubeConfigData();

        // the most reliable way of fetching all download links no matter what
        // query: /youtubei/v1/player for some additional data
        $player_response = $this->getPlayerApiResponse($video_id, $youtube_config_data);

        if (!$player_response->isStatusOkay()) {
            throw new VideoPlayerApiException('Get Player Api Error: ' . $player_response->getResponse()->body);
        }

        // get player.js location that holds URL signature decipher function
        $player_url = $page->getPlayerScriptUrl();
        $response = $this->getBrowser()->get($player_url);
        $player = new VideoPlayerJs($response);

        $links = SignatureLinkParser::parseLinks($player_response, $player);

        // since we already have that information anyways...
        $info = VideoInfoMapper::fromInitialPlayerResponse($page->getPlayerResponse());

        return new DownloadOptions($links, $info);
    }

    /**
     * @param string $videoId
     * @return YouTubeCaption[]
     */
    public function getCaptions(string $videoId): array
    {
        $pageResponse = $this->getPage($videoId);
        $data = $pageResponse->getPlayerResponse();

        return array_map(function ($item) {

            $temp = new YouTubeCaption();
            $temp->name = Utils::arrayGet($item, "name.simpleText");
            $temp->baseUrl = Utils::arrayGet($item, "baseUrl");
            $temp->languageCode = Utils::arrayGet($item, "languageCode");
            $vss = Utils::arrayGet($item, "vssId");
            $temp->isAutomatic = Utils::arrayGet($item, "kind") === "asr" || strpos($vss, "a.") !== false;
            return $temp;

        }, $data->getCaptionTracks());
    }

    public function getThumbnails(string $videoId): array
    {
        $videoId = Utils::extractVideoId($videoId);

        if ($videoId) {
            return [
                'default' => "https://img.youtube.com/vi/{$videoId}/default.jpg",
                'medium' => "https://img.youtube.com/vi/{$videoId}/mqdefault.jpg",
                'high' => "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg",
                'standard' => "https://img.youtube.com/vi/{$videoId}/sddefault.jpg",
                'maxres' => "https://img.youtube.com/vi/{$videoId}/maxresdefault.jpg",
            ];
        }

        return [];
    }
}
