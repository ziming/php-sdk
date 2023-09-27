<?php

namespace Statsig;


/**
 * From https://www.uuidgenerator.net/dev-corner/php
 */
function guidv4($data = null)
{
    // Generate 16 bytes (128 bits) of random data or use the data passed into the function.
    $data = $data ?? random_bytes(16);
    assert(strlen($data) == 16);

    // Set version to 0100
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    // Set bits 6-7 to 10
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

    // Output the 36 character UUID.
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

namespace Statsig;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;

class StatsigNetwork
{
    private string $key;
    private string $session_id;
    private array $guzzle_options;
    private Client $client;

    function __construct()
    {
        $this->session_id = guidv4();
        $this->guzzle_options = [
          'timeout' => 30,
          'connect_timeout' => 10,
        ];
        
    }

    function setOptions(array $options)
    {
        $this->guzzle_options = $options;
    }

    function setSdkKey(string $key)
    {
        $this->key = $key;
        $this->client = new Client([
          'base_uri' => 'https://statsigapi.net/v1/',
          'headers' => [
              'STATSIG-API-KEY' => $this->key,
              'STATSIG-SERVER-SESSION-ID' => $this->session_id,
              'STATSIG-SDK-TYPE' => StatsigMetadata::SDK_TYPE,
              'STATSIG-SDK-VERSION' => StatsigMetadata::VERSION,
              'Content-Type' => 'application/json'
          ]
      ]);
    }

    function logEvents($events)
    {
        $req_body = [
            'events' => $events,
            'statsigMetadata' => StatsigMetadata::getJson()
        ];
        return $this->postRequest("rgstr", json_encode($req_body));
    }


    function postRequest(string $endpoint, string $input)
    {
        $response = $this->client->post($endpoint, [
            RequestOptions::BODY => $input,
            RequestOptions::HTTP_ERRORS => false,
        ] + $this->guzzle_options); 

        $body = $response->getBody()->getContents();
        
        return json_decode($body, true, 512, JSON_BIGINT_AS_STRING);
    }

    function multiGetRequest(array $requests): array
    {
        $responses = [];
        
        $requestsPool = function () use ($requests) {
          foreach ($requests as $key => $value) {
              $request = new Request('GET', $value["url"], $value["headers"]);
              yield $key => $request;
          }
      };

      $pool = new Pool($this->client, $requestsPool(), [
          'concurrency' => 5,
          'fulfilled' => function ($response, $originalKey) use (&$responses) {
              $contents = $response->getBody()->getContents();
              $responses[$originalKey] = [
                  "headers" => $response->getHeaders(),
                  "data" => $contents
              ];
          },
          'rejected' => function ($reason, $index) {
              // No op - will retry to download from network next sync
          },
        ]);

        // Wait for all requests to complete
        $pool->promise()->wait();

        return $responses;
    }
}
