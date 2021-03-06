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

namespace HumusAmqpModule\Service;

use HumusAmqpModule\Consumer;
use HumusAmqpModule\Exception;
use Zend\ServiceManager\AbstractPluginManager;
use Zend\ServiceManager\ServiceLocatorInterface;

class ConsumerAbstractServiceFactory extends AbstractAmqpQueueAbstractServiceFactory
{
    /**
     * @var string Second-level configuration key indicating connection configuration
     */
    protected $subConfigKey = 'consumers';

    /**
     * Create service with name
     *
     * @param ServiceLocatorInterface $serviceLocator
     * @param $name
     * @param $requestedName
     * @return mixed
     * @throws Exception\RuntimeException
     */
    public function createServiceWithName(ServiceLocatorInterface $serviceLocator, $name, $requestedName)
    {
        // get global service locator, if we are in a plugin manager
        if ($serviceLocator instanceof AbstractPluginManager) {
            $serviceLocator = $serviceLocator->getServiceLocator();
        }

        $spec = $this->getSpec($serviceLocator, $name, $requestedName);
        $this->validateSpec($serviceLocator, $spec, $requestedName);

        $connection = $this->getConnection($serviceLocator, $spec);
        $channel    = $this->createChannel($connection, $spec);

        $config = $this->getConfig($serviceLocator);
        $queues = array();

        foreach ($spec['queues'] as $queue) {
            if ($this->useAutoSetupFabric($spec)) {
                // will create the exchange to declare it on the channel
                // the created exchange will not be used afterwards
                $exchangeName = $config['queues'][$queue]['exchange'];
                $this->getExchange($serviceLocator, $channel, $exchangeName, $this->useAutoSetupFabric($spec));
            }

            $queueSpec = $this->getQueueSpec($serviceLocator, $queue);
            $queues[] = $this->getQueue($queueSpec, $channel, $this->useAutoSetupFabric($spec));
        }

        $idleTimeout = isset($spec['idle_timeout']) ? $spec['idle_timeout'] : 5.0;
        $waitTimeout = isset($spec['wait_timeout']) ? $spec['wait_timeout'] : 1000;

        $consumer = new Consumer($queues, $idleTimeout, $waitTimeout);

        // @todo: inject real logger instance
        $logger = new \Zend\Log\Logger();
        $writers = new \Zend\Stdlib\SplPriorityQueue();
        $writers->insert(new \Zend\Log\Writer\Stream(STDOUT), 0);
        $logger->setWriters($writers);
        $consumer->setLogger($logger);

        $callbackManager = $this->getCallbackManager($serviceLocator);
        $callback        = $callbackManager->get($spec['callback']);

        $consumer->setDeliveryCallback($callback);

        if (isset($spec['flush_callback'])) {
            $flushCallback = $callbackManager->get($spec['flush_callback']);
            $consumer->setFlushCallback($flushCallback);
        }

        return $consumer;
    }

    /**
     * @param ServiceLocatorInterface $serviceLocator
     * @param array $spec
     * @param string $requestedName
     * @throws Exception\InvalidArgumentException
     */
    protected function validateSpec(ServiceLocatorInterface $serviceLocator, array $spec, $requestedName)
    {
        // queues are required
        if (!isset($spec['queues'])) {
            throw new Exception\InvalidArgumentException(
                'Queues are missing for consumer ' . $requestedName
            );
        }

        // callback is required
        if (!isset($spec['callback'])) {
            throw new Exception\InvalidArgumentException(
                'No delivery callback specified for consumer ' . $requestedName
            );
        }

        $defaultConnection = $this->getDefaultConnectionName($serviceLocator);

        if (isset($spec['connection'])) {
            $connection = $spec['connection'];
        } else {
            $connection = $defaultConnection;
        }

        $config  = $this->getConfig($serviceLocator);
        foreach ($spec['queues'] as $queue) {
            // validate queue existence
            if (!isset($config['queues'][$queue])) {
                throw new Exception\InvalidArgumentException(
                    'Queue ' . $queue . ' is missing in the queue configuration'
                );
            }

            // validate queue connection
            $testConnection = isset($config['queues'][$queue]['connection'])
                ? $config['queues'][$queue]['connection']
                : $defaultConnection;

            if ($testConnection != $connection) {
                throw new Exception\InvalidArgumentException(
                    'The queue connection for queue ' . $queue . ' (' . $testConnection . ') does not '
                    . 'match the consumer connection for consumer ' . $requestedName . ' (' . $connection . ')'
                );
            }

            // exchange binding is required
            if (!isset($config['exchanges'][$config['queues'][$queue]['exchange']])) {
                throw new Exception\InvalidArgumentException(
                    'The queues exchange ' . $queue['exchange'] . ' is missing in the exchanges configuration'
                );
            }

            // validate exchange connection
            $exchange = $config['exchanges'][$config['queues'][$queue]['exchange']];
            $testConnection = isset($exchange['connection']) ? $exchange['connection'] : $defaultConnection;
            if ($testConnection != $connection) {
                throw new Exception\InvalidArgumentException(
                    'The exchange connection for exchange ' . $exchange . ' (' . $testConnection . ') does not '
                    . 'match the consumer connection for consumer ' . $requestedName . ' (' . $connection . ')'
                );
            }
        }
    }
}
