<?php

namespace Akeneo\Bundle\BatchBundle\Command;

use Akeneo\Bundle\BatchBundle\Job\JobInstanceFactory;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Create a JobInstance
 *
 * @author    Nicolas Dupont <nicolas@akeneo.com>
 * @copyright 2015 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class CreateJobCommand extends Command
{
    /** @var string */
    protected static $defaultName = 'akeneo:batch:create-job';

    /** @var JobInstanceFactory */
    private $jobInstanceFactory;

    /** @var ObjectManager */
    private $objectManager;

    /**
     * @param JobInstanceFactory $jobInstanceFactory
     * @param ObjectManager $objectManager
     */
    public function __construct(JobInstanceFactory $jobInstanceFactory, ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
        $this->jobInstanceFactory = $jobInstanceFactory;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Create a job instance')
            ->addArgument('connector', InputArgument::REQUIRED, 'Connector code')
            ->addArgument('job', InputArgument::REQUIRED, 'Job name')
            ->addArgument('type', InputArgument::REQUIRED, 'Job type')
            ->addArgument('code', InputArgument::REQUIRED, 'Job instance code')
            ->addArgument('config', InputArgument::REQUIRED, 'Job instance config')
            ->addArgument('label', InputArgument::OPTIONAL, 'Job instance label');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $connector = $input->getArgument('connector');
        $job = $input->getArgument('job');
        $type = $input->getArgument('type');
        $code = $input->getArgument('code');
        $label = $input->getArgument('label');
        $label = $label ? $label : $code;
        $jsonConfig = $input->getArgument('config');
        $rawConfig = json_decode($jsonConfig, true);

        $jobInstance = $this->jobInstanceFactory->createJobInstance($type);
        $jobInstance->setConnector($connector);
        $jobInstance->setAlias($job);
        $jobInstance->setCode($code);
        $jobInstance->setLabel($label);
        $jobInstance->setRawConfiguration($rawConfig);

        $this->objectManager->persist($jobInstance);
        $this->objectManager->flush();
    }
}
