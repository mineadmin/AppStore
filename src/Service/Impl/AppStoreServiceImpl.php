<?php

declare(strict_types=1);
/**
 * This file is part of MineAdmin.
 *
 * @link     https://www.mineadmin.com
 * @document https://doc.mineadmin.com
 * @contact  root@imoi.cn
 * @license  https://github.com/mineadmin/MineAdmin/blob/master/LICENSE
 */

namespace Xmo\AppStore\Service\Impl;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Guzzle\ClientFactory;
use Xmo\AppStore\Plugin;
use Xmo\AppStore\Service\AppStoreService;

use function Hyperf\Support\env;
use function Hyperf\Translation\trans;

final class AppStoreServiceImpl implements AppStoreService
{
    private readonly Client $client;

    private readonly array $config;

    public function __construct(
        ClientFactory $clientFactory,
        ConfigInterface $config
    ) {
        $this->client = $clientFactory->create([
            'base_uri' => 'https://www.mineadmin.com/server/store/',
            'timeout' => 10.0,
        ]);
        $this->config = $config->get('mine-extension');
    }

    /**
     * @throws GuzzleException
     * @throws \JsonException
     */
    public function request(string $uri, array $data = []): array
    {
        $response = $this->client->post($uri, [
            'json' => $data,
            'headers' => [
                'Access-Token' => $this->getAccessToken(),
            ],
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(trans('app-store.store.response_fail'));
        }
        $result = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(json_last_error_msg());
        }
        return $result;
    }

    /**
     * Get the list of remote plugins.
     */
    public function list(array $params): array
    {
        return $this->request(__FUNCTION__, $params);
    }

    /**
     * Get the details of the specified plugin.
     */
    public function view(string $plugin): array
    {
        return $this->request(__FUNCTION__, compact('plugin'));
    }

    /**
     * Download the specified plug-in to a local directory.
     */
    public function download(string $plugin): bool
    {
        $downloadToken = $this->request(__FUNCTION__, compact('plugin'))[0] ?? '';
        $tmpFile = sys_get_temp_dir() . '/' . uniqid('mine', true) . '.zip';
        $tmpFileResource = fopen(sys_get_temp_dir() . '/' . uniqid('mine', true) . '.zip', 'wb+');
        $response = $this->client->get('download_file?token=' . $downloadToken, [
            RequestOptions::SINK => $tmpFileResource,
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Failed to download plugin');
        }
        $zip = new \ZipArchive();
        if ($zip->open($tmpFile) !== true) {
            throw new \RuntimeException('Failed to open the zip file');
        }
        $zip->extractTo(Plugin::PLUGIN_PATH);
        $zip->close();
        return true;
    }

    /**
     * Get MineAdmin developer credentials.
     */
    private function getAccessToken(): string
    {
        $accessToken = $this->config['access_token'] ?? env('MINE_ACCESS_TOKEN');
        if (empty($accessToken)) {
            throw new \RuntimeException(trans('app-store.access_token_null'));
        }
        return (string) $accessToken;
    }
}
