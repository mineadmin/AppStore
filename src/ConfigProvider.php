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

namespace Xmo\AppStore;

use Xmo\AppStore\Service\AppStoreService;
use Xmo\AppStore\Service\Impl\AppStoreServiceImpl;

class ConfigProvider
{
    public function __invoke()
    {
        return [
            // 合并到  config/autoload/annotations.php 文件
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
            'dependencies' => [
                AppStoreService::class => AppStoreServiceImpl::class,
            ],
        ];
    }
}