<?php

use FR3D\SwaggerAssertions\PhpUnit\AssertsTrait;
use FR3D\SwaggerAssertions\SchemaManager;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/**
 * PHPUnit integration example.
 */
class SwaggerAssert extends \PHPUnit_Framework_TestCase
{
    use AssertsTrait;

    /**
     * @var SchemaManager
     */
    protected static $schemaManager;

    /**
     * @var ClientInterface
     */
    protected $guzzleHttpClient;

    public function __construct()
    {

        if (version_compare(ClientInterface::VERSION, '6.0', '>=')) {
            self::markTestSkipped('This example requires Guzzle V5 installed');
        }        

        $this->guzzleHttpClient = new Client(['headers' => ['User-Agent' => 'https://github.com/Maks3w/SwaggerAssertions']]);

    }

    public function setSchema($schema_url) {
        self::$schemaManager = new SchemaManager($schema_url);
    }

    public function testBodyMatchDefinition($api_url, $schema_path)
    {
        $request = $this->guzzleHttpClient->createRequest('GET', $api_url);
        $request->addHeader('Accept', 'application/json');

        $response = $this->guzzleHttpClient->send($request);
        $responseBody = $response->json(['object' => true]);
        
        $this->assertResponseBodyMatch($responseBody, self::$schemaManager, '/' . $schema_path, 'get', 200);
    }
}
