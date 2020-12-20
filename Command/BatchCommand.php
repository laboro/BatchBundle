<?php

namespace Akeneo\Bundle\BatchBundle\Command;

use Akeneo\Bundle\BatchBundle\Connector\ConnectorRegistry;
use Akeneo\Bundle\BatchBundle\Job\DoctrineJobRepository;
use Akeneo\Bundle\BatchBundle\Job\ExitStatus;
use Akeneo\Bundle\BatchBundle\Monolog\Handler\BatchLogHandler;
use Akeneo\Bundle\BatchBundle\Notification\MailNotifier;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Monolog\Handler\StreamHandler;
use Doctrine\ORM\EntityManager;

/**
 * Batch command
 *
 * @author    Benoit Jacquemont <benoit@akeneo.com>
 * @copyright 2013 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/MIT MIT
 */
class BatchCommand extends Command
{
    /** @var string */
    protected static $defaultName = 'akeneo:batch:job';

    /** @var LoggerInterface */
    private $logger;

    /** @var BatchLogHandler */
    private $batchLogHandler;

    /** @var ManagerRegistry */
    private $doctrine;

    /** @var ValidatorInterface */
    private $validator;

    /** @var DoctrineJobRepository */
    private $jobRepository;

    /** @var MailNotifier */
    private $mailNotifier;

    /** @var ConnectorRegistry */
    private $connectorRegistry;

    /**
     * @param LoggerInterface $logger
     * @param BatchLogHandler $batchLogHandler
     * @param ManagerRegistry $managerRegistry
     * @param DoctrineJobRepository $jobRepository
     * @param ValidatorInterface $validator
     * @param MailNotifier $mailNotifier
     * @param ConnectorRegistry $connectorRegistry
     */
    public function __construct(
        LoggerInterface $logger,
        BatchLogHandler $batchLogHandler,
        ManagerRegistry $managerRegistry,
        DoctrineJobRepository $jobRepository,
        ValidatorInterface $validator,
        MailNotifier $mailNotifier,
        ConnectorRegistry $connectorRegistry
    ) {
        $this->logger = $logger;
        $this->batchLogHandler = $batchLogHandler;
        $this->doctrine = $managerRegistry;
        $this->jobRepository = $jobRepository;
        $this->validator = $validator;
        $this->mailNotifier = $mailNotifier;
        $this->connectorRegistry = $connectorRegistry;

        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Launch a registered job instance')
            ->addArgument('code', InputArgument::REQUIRED, 'Job instance code')
            ->addArgument('execution', InputArgument::OPTIONAL, 'Job execution id')
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_REQUIRED,
                'Override job configuration (formatted as json. ie: ' .
                'php app/console akeneo:batch:job -c \'[{"reader":{"filePath":"/tmp/foo.csv"}}]\' ' .
                'acme_product_import)'
            )
            ->addOption(
                'email',
                null,
                InputOption::VALUE_REQUIRED,
                'The email to notify at the end of the job execution'
            )
            ->addOption(
                'no-log',
                null,
                InputOption::VALUE_NONE,
                'Don\'t display logs'
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $noLog = $input->getOption('no-log');
        if (!$noLog) {
            // Fixme: Use ConsoleHandler available on next Symfony version (2.4 ?)
            $this->logger->pushHandler(new StreamHandler('php://stdout'));
        }

        $code = $input->getArgument('code');
        $jobInstance = $this->getJobManager()->getRepository('AkeneoBatchBundle:JobInstance')->findOneByCode($code);
        if (!$jobInstance) {
            throw new \InvalidArgumentException(sprintf('Could not find job instance "%s".', $code));
        }

        $job = $this->connectorRegistry->getJob($jobInstance);
        $jobInstance->setJob($job);

        // Override job configuration
        if ($config = $input->getOption('config')) {
            $job->setConfiguration(
                $this->decodeConfiguration($config)
            );
        }

        // Override mail notifier recipient email
        if ($email = $input->getOption('email')) {
            $errors = $this->validator->validateValue($email, new Assert\Email());
            if (count($errors) > 0) {
                throw new \RuntimeException(
                    sprintf('Email "%s" is invalid: %s', $email, $this->getErrorMessages($errors))
                );
            }
            $this
                ->mailNotifier
                ->setRecipientEmail($email);
        }

        // We merge the JobInstance from the JobManager EntitManager to the DefaultEntityManager
        // in order to be able to have a working UniqueEntity validation
        $defaultJobInstance = $this->getDefaultEntityManager()->merge($jobInstance);
        $defaultJobInstance->setJob($job);

        $errors = $this->validator->validate($defaultJobInstance, ['Default', 'Execution']);
        if (count($errors) > 0) {
            throw new \RuntimeException(
                sprintf('Job "%s" is invalid: %s', $code, $this->getErrorMessages($errors))
            );
        }

        $this->getDefaultEntityManager()->clear(get_class($jobInstance));

        $executionId = $input->getArgument('execution');
        if ($executionId) {
            $jobExecution = $this->getJobManager()->getRepository('AkeneoBatchBundle:JobExecution')->find($executionId);
            if (!$jobExecution) {
                throw new \InvalidArgumentException(sprintf('Could not find job execution "%s".', $executionId));
            }
            if (!$jobExecution->getStatus()->isStarting()) {
                throw new \RuntimeException(
                    sprintf('Job execution "%s" has invalid status: %s', $executionId, $jobExecution->getStatus())
                );
            }
        } else {
            $jobExecution = $job->getJobRepository()->createJobExecution($jobInstance);
        }
        $jobExecution->setJobInstance($jobInstance);

        $jobExecution->setPid(getmypid());

        $this->batchLogHandler
            ->setSubDirectory($jobExecution->getId());

        $job->execute($jobExecution);

        $job->getJobRepository()->updateJobExecution($jobExecution);

        if (ExitStatus::COMPLETED === $jobExecution->getExitStatus()->getExitCode()) {
            $output->writeln(
                sprintf(
                    '<info>%s %s has been successfully executed.</info>',
                    ucfirst($jobInstance->getType()),
                    $jobInstance->getCode()
                )
            );
        } else {
            $output->writeln(
                sprintf(
                    '<error>An error occured during the %s execution.</error>',
                    $jobInstance->getType()
                )
            );
            $verbose = $input->getOption('verbose');
            $this->writeExceptions($output, $jobExecution->getFailureExceptions(), $verbose);
            foreach ($jobExecution->getStepExecutions() as $stepExecution) {
                $this->writeExceptions($output, $stepExecution->getFailureExceptions(), $verbose);
            }
        }
    }

    /**
     * Writes failure exceptions to the output
     *
     * @param OutputInterface $output
     * @param array[]         $exceptions
     * @param boolean         $verbose
     */
    protected function writeExceptions(OutputInterface $output, array $exceptions, $verbose)
    {
        foreach ($exceptions as $exception) {
            $output->write(
                sprintf(
                    '<error>Error #%s in class %s: %s</error>',
                    $exception['code'],
                    $exception['class'],
                    strtr($exception['message'], $exception['messageParameters'])
                ),
                true
            );
            if ($verbose) {
                $output->write(sprintf('<error>%s</error>', $exception['trace']), true);
            }
        }
    }

    /**
     * @return EntityManager
     */
    protected function getJobManager()
    {
        return $this->jobRepository->getJobManager();
    }

    /**
     * @return EntityManager
     */
    protected function getDefaultEntityManager()
    {
        return $this->doctrine->getManager();
    }

    /**
     * @param ConstraintViolationList $errors
     *
     * @return string
     */
    private function getErrorMessages(ConstraintViolationList $errors)
    {
        $errorsStr = '';

        foreach ($errors as $error) {
            $errorsStr .= sprintf("\n  - %s", $error);
        }

        return $errorsStr;
    }

    /**
     * @param string $data
     *
     * @return array
     */
    private function decodeConfiguration($data)
    {
        $config = json_decode(stripcslashes($data), true);

        switch (json_last_error()) {
            case JSON_ERROR_DEPTH:
                $error = 'Maximum stack depth exceeded';
                break;
            case JSON_ERROR_STATE_MISMATCH:
                $error = 'Underflow or the modes mismatch';
                break;
            case JSON_ERROR_CTRL_CHAR:
                $error = 'Unexpected control character found';
                break;
            case JSON_ERROR_SYNTAX:
                $error = 'Syntax error, malformed JSON';
                break;
            case JSON_ERROR_UTF8:
                $error = 'Malformed UTF-8 characters, possibly incorrectly encoded';
                break;
            default:
                return $config;
        }

        throw new \InvalidArgumentException($error);
    }
}
