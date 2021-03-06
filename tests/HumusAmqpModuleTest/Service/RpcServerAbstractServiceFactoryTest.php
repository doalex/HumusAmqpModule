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
use HumusAmqpModule\PluginManager\RpcServer as RpcServerPluginManager;
use HumusAmqpModule\Service\ConnectionAbstractServiceFactory;
use HumusAmqpModule\Service\RpcServerAbstractServiceFactory;
use Zend\ServiceManager\ServiceManager;

class RpcServerAbstractServiceFactoryTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var ServiceManager
     */
    protected $services;

    /**
     * @var RpcServerAbstractServiceFactory
     */
    protected $components;

    public function setUp()
    {
        $config = array(
            'humus_amqp_module' => array(
                'rpc_servers' => array(
                    'test-rpc-server' => array(
                        'callback' => 'test-callback',
                        'qos' => array(
                            'prefetchSize' => 0,
                            'prefetchCount' => 1,
                        ),
                    ),
                ),
            )
        );

        $channel = $this->getMock('AmqpChannel', array(), array(), '', false);

        $connectionMock = $this->getMock('AMQPConnection', array(), array(), '', false);
        $connectionMock
            ->expects($this->any())
            ->method('channel')
            ->willReturn($channel);

        $connectionManager = $this->getMock('HumusAmqpModule\PluginManager\Connection');
        $connectionManager
            ->expects($this->once())
            ->method('get')
            ->with('default')
            ->willReturn($connectionMock);

        $services    = $this->services = new ServiceManager();
        $services->setAllowOverride(true);
        $services->setService('Config', $config);

        $services->setService('HumusAmqpModule\PluginManager\Connection', $connectionManager);

        $components = $this->components = new RpcServerAbstractServiceFactory();
        $services->setService('HumusAmqpModule\PluginManager\RpcClient', $rpcsm = new RpcServerPluginManager());
        $rpcsm->addAbstractFactory($components);
        $rpcsm->setServiceLocator($services);

        $services->setService('HumusAmqpModule\PluginManager\Callback', $callbackManager = new CallbackPluginManager());
        $callbackManager->setInvokableClass('test-callback', __NAMESPACE__ . '\TestAsset\TestCallback');
        $callbackManager->setServiceLocator($services);
    }

    public function testCreateRpcServer()
    {
        $rpcServer = $this->components->createServiceWithName($this->services, 'test-rpc-server', 'test-rpc-server');
        $this->assertInstanceOf('HumusAmqpModule\Amqp\RpcServer', $rpcServer);
        /* @var $rpcServer \HumusAmqpModule\RpcServer */
        $this->assertEquals('direct', $rpcServer->getExchangeOptions()->getType());
    }

    /**
     * @expectedException HumusAmqpModule\Exception\InvalidArgumentException
     * @expectedExceptionMessage callback is missing for rpc server
     */
    public function testCreateRpcServerWithoutCallback()
    {
        $config = $this->services->get('Config');
        unset($config['humus_amqp_module']['rpc_servers']['test-rpc-server']['callback']);
        $this->services->setService('Config', $config);

        $this->components->createServiceWithName($this->services, 'test-rpc-server', 'test-rpc-server');
    }
}
