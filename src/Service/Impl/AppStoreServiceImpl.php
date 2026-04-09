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

namespace Mine\AppStore\Service\Impl;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use Hyperf\Collection\Arr;
use Hyperf\Collection\Collection;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Guzzle\ClientFactory;
use Hyperf\Guzzle\CoroutineHandler;
use Mine\AppStore\Plugin;
use Mine\AppStore\Service\AppStoreService;

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
        /**
         * 修复一：强制使用 CoroutineHandler
         *
         * 问题根源：bin/hyperf.php 中定义了 SWOOLE_HOOK_ALL（包含 SWOOLE_HOOK_NATIVE_CURL），
         * Hyperf 的 ClientFactory 检测到该 flag 后，认为 cURL 已被 Swoole Hook 接管，
         * 因此不会自动切换为 CoroutineHandler，而是退化使用 hooked cURL。
         *
         * 修复方案：手动创建 HandlerStack 并指定 CoroutineHandler，
         * 绕过 ClientFactory 的自动检测逻辑，直接使用 Swoole 原生非阻塞 HTTP 客户端，
         */
        $stack = HandlerStack::create(new CoroutineHandler());
        $this->client = $clientFactory->create([
            'base_uri' => 'https://www.mineadmin.com/server/server/',
            'timeout' => 10.0,
            'handler' => $stack,
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
        $result = json_decode($response->getBody()->getContents(), true, 512, \JSON_THROW_ON_ERROR);
        if (json_last_error() !== \JSON_ERROR_NONE) {
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
    public function view(string $identifier): array
    {
        return $this->request(__FUNCTION__, compact('identifier'));
    }

    public function payApp(): array
    {
        return $this->request('pay_app_list');
    }

    public function getOtherApp(string $username): array
    {
        return $this->request('get_other_app', compact('username'));
    }

    /**
     * Download the specified plug-in to a local directory.
     */
    public function download(string $identifier, string $version): bool
    {
        $localPluginPath = Plugin::PLUGIN_PATH . \DIRECTORY_SEPARATOR . $identifier;
        if (file_exists($localPluginPath)) {
            throw new \RuntimeException(\sprintf('The plugin %s already exists', $identifier));
        }

        $originData = $this->request(__FUNCTION__, [
            'identifier' => $identifier,
            'version' => $version,
        ]);
        $downloadResponse = Collection::make($originData);
        if (! $downloadResponse->get('success')) {
            throw new \RuntimeException('服务端返回错误' . $downloadResponse->get('message'));
        }

        $file_token = Arr::get($originData, 'data.token');
        if (empty($file_token)) {
            throw new \RuntimeException('Failed to get download token');
        }
        $downLoadFileOriginData = $this->request('download_file', compact('file_token'));
        $downLoadFileResponse = Collection::make($downLoadFileOriginData);
        if (! $downLoadFileResponse->get('success')) {
            throw new \RuntimeException('服务端返回错误' . $downLoadFileResponse->get('message'));
        }
        $file_url = Arr::get($downLoadFileOriginData, 'data.url');
        if (empty($file_url)) {
            throw new \RuntimeException('Failed to get download url');
        }
        $tmpFile = sys_get_temp_dir() . '/' . uniqid('mine', true) . '.zip';
        $response = $this->client->get($file_url);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Failed to download plugin');
        }
        file_put_contents($tmpFile, $response->getBody()->getContents());

        $zip = new \ZipArchive();
        $zip->open($tmpFile);
        if ($zip->status !== \ZipArchive::ER_OK) {
            throw new \RuntimeException('Failed to open the zip file');
        }
        $zip->extractTo(Plugin::PLUGIN_PATH . \DIRECTORY_SEPARATOR . explode('/', $identifier)[0]);
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
