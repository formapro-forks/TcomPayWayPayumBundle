<?php

namespace Locastic\TcomPaywayPayumBundle;

use Locastic\TcomPayWay\AuthorizeForm\Model\Payment as PaymentOffsite;
use Locastic\TcomPaywayPayumBundle\Action\CaptureOffsiteAction;
use Locastic\TcomPaywayPayumBundle\Action\StatusAction;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\PaymentFactoryInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Payum\Core\PaymentFactory as CorePaymentFactory;

class TcomOffsitePaymentFactory implements PaymentFactoryInterface
{
    /**
     * @var PaymentFactoryInterface
     */
    protected $corePayementFactory;

    /**
     * @var array
     */
    private $defaultConfig;

    /**
     * @param array $defaultConfig
     * @param PaymentFactoryInterface $corePayementFactory
     */
    public function __construct(array $defaultConfig = array(), PaymentFactoryInterface $corePayementFactory = null)
    {
        $this->corePayementFactory = $corePayementFactory ?: new CorePaymentFactory();
        $this->defaultConfig = $defaultConfig;
    }

    /**
     * {@inheritDoc}
     */
    public function createConfig(array $config = array())
    {
        $config = ArrayObject::ensureArrayObject($config);
        $config->defaults($this->defaultConfig);
        $config->defaults($this->corePaymentFactory->createConfig((array)$config));

        $config->defaults(
            array(
                'payum.factory_name' => 'tcompayway_offsite',
                'payum.factory_title' => 'TcomPayWay Offsite',
                'payum.action.capture' => new CaptureOffsiteAction(),
                'payum.action.status' => new StatusAction(),
            )
        );

        if (false == $config['payum.api']) {
            $config['payum.default_options'] = array(
                'shop_id' => '',
                'secret_key' => '',
                'authorization_type' => ''
            );
            $config->defaults($config['payum.default_options']);
            $config['payum.required_options'] = array(
                'shop_id',
                'secret_key',
            );
            $config['payum.api'] = function (ArrayObject $config) {
                $config->validateNotEmpty($config['payum.required_options']);
                $api = new PaymentOffsite(
                    $config['shop_id'],
                    $config['secret_key'],
                    null,
                    null,
                    $config['authorization_type'],
                    null,
                    null
                );

                return $api;
            };
        }

        return (array)$config;
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $config = array())
    {
        return $this->corePayementFactory->create($this->createConfig($config));
    }
}
