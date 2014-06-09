<?php

namespace Composer\Test\Mock;

use Composer\Config;

class FactoryWithTestReposMock extends FactoryMock{

    public static function createConfig()
    {
        $config = new Config();

        $config->merge(array(
            'config' => array('home' => sys_get_temp_dir().'/composer-test'),
            'repositories' => array(
                'test_com' => array('type' => 'composer', 'url' => 'http://test')
            )
        ));

        return $config;
    }
}
 