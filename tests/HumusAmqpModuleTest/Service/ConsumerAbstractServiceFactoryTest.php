<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license.
 */

namespace HumusAmqpModuleTest\Service;

use HumusAmqpModule\PluginManager\Callback as CallbackPluginManager;
use HumusAmqpModule\PluginManager\Connection as ConnectionPluginManager;
use HumusAmqpModule\PluginManager\Consumer as ConsumerPluginManager;
use HumusAmqpModule\Service\ConnectionAbstractServiceFactory;
use HumusAmqpModule\Service\ConsumerAbstractServiceFactory;
use HumusAmqpModule\Service\ProducerAbstractServiceFactory;
use Zend\Mvc\Service\ServiceManagerConfig;
use Zend\ServiceManager\ServiceManager;

class ConsumerAbstractServiceFactoryTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var ServiceManager
     */
    protected $services;

    /**
     * @var ProducerAbstractServiceFactory
     */
    protected $components;

    public function setUp()
    {
        $config = array(
            'humus_amqp_module' => array(
                'classes' => array(
                    'connection' => 'PhpAmqpLib\Connection\AMQPConnection',
                    'lazy_connection' => 'PhpAmqpLib\Connection\AMQPLazyConnection',
                    'consumer' => 'HumusAmqpModule\Amqp\Consumer',
                ),
                'consumers' => array(
                    'test-consumer' => array(
                        'connection' => 'default',
                        /* 'class' => 'MyCustomConsumerClass' */
                        'exchange_options' => array(
                            'name' => 'demo-exchange',
                            'type' => 'direct',
                        ),
                        'queue_options' => array(
                            'name' => 'myconsumer-queue',
                        ),
                        'qos_options' => array(
                            'prefetchSize' => 0,
                            'prefetchCount' => 0
                        ),
                        'idle_timeout' => 20,
                        'auto_setup_fabric' => false,
                        'callback' => 'test-callback'
                    ),
                ),
            )
        );

        $channel = $this->getMock('PhpAmqpLib\Channel\AmqpChannel', array(), array(), '', false);

        $connectionMock = $this->getMock('PhpAmqpLib\Connection\AMQPLazyConnection', array(), array(), '', false);
        $connectionMock
            ->expects($this->any())
            ->method('channel')
            ->willReturn($channel);

        $connectionManager = $this->getMock('HumusAmqpModule\PluginManager\Connection');
        $connectionManager
            ->expects($this->any())
            ->method('get')
            ->with('default')
            ->willReturn($connectionMock);

        $services    = $this->services = new ServiceManager();
        $services->setAllowOverride(true);
        $services->setService('Config', $config);

        $services->setService('HumusAmqpModule\PluginManager\Connection', $connectionManager);

        $callbackManager = new CallbackPluginManager();
        $callbackManager->setInvokableClass('test-callback', __NAMESPACE__ . '\TestAsset\TestCallback');
        $services->setService('HumusAmqpModule\PluginManager\Callback', $callbackManager);


        $callbackManager->setServiceLocator($services);

        $components = $this->components = new ConsumerAbstractServiceFactory();
        $services->setService('HumusAmqpModule\PluginManager\Consumer', $consumerManager = new ConsumerPluginManager());
        $consumerManager->addAbstractFactory($components);
        $consumerManager->setServiceLocator($services);
    }

    public function testCreateConsumer()
    {
        $consumer = $this->components->createServiceWithName($this->services, 'test-consumer', 'test-consumer');
        $consumer2 = $this->components->createServiceWithName($this->services, 'test-consumer', 'test-consumer');
        $this->assertNotSame($consumer, $consumer2);
        $this->assertInstanceOf('HumusAmqpModule\Amqp\Consumer', $consumer);
        /* @var $producer \HumusAmqpModule\Amqp\Producer */
        $this->assertEquals('demo-exchange', $consumer->getExchangeOptions()->getName());
        $this->assertEquals('direct', $consumer->getExchangeOptions()->getType());
        $this->assertEquals('myconsumer-queue', $consumer->getQueueOptions()->getName());
    }

    public function testCreateConsumerWithCustomClassAndWithoutConnectionName()
    {
        $config = $this->services->get('Config');
        $config['humus_amqp_module']['consumers']['test-consumer']['class'] = __NAMESPACE__
            . '\TestAsset\CustomConsumer';
        unset($config['humus_amqp_module']['consumers']['test-consumer']['connection']);
        $this->services->setService('Config', $config);

        $consumer = $this->components->createServiceWithName($this->services, 'test-consumer', 'test-consumer');
        $this->assertInstanceOf('HumusAmqpModuleTest\Service\TestAsset\CustomConsumer', $consumer);
    }

    /**
     * @expectedException HumusAmqpModule\Exception\RuntimeException
     * @expectedExceptionMessage Plugin of type stdClass is invalid; must be a callable
     */
    public function testCreateConsumerWithInvalidCallback()
    {
        $config = $this->services->get('Config');
        $config['humus_amqp_module']['consumers']['test-consumer']['callback'] = 'stdClass';
        $this->services->setService('Config', $config);

        $this->components->createServiceWithName($this->services, 'test-consumer', 'test-consumer');
    }
}
