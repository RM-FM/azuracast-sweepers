<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Console;

use App\Entity\Repository\SettingsRepository;
use Plugin\AzuracastSweepers\Service\DetectorAccess;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the AzuraCast address and, with --new-key, a new API key: the input of the detector's unattended setup.
 */
#[AsCommand(name: self::NAME, description: 'Prepare the Intro Detector account (used by bin/setup.sh).')]
final class DetectorAccountCommand extends Command
{
    public const string NAME = 'sweepers:detector-account';

    public function __construct(
        private readonly DetectorAccess $access,
        private readonly SettingsRepository $settings
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('new-key', null, InputOption::VALUE_NONE, 'Create an API key for the detector account');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->access->ensureAccount();
        $output->writeln((string)$this->settings->readSettings()->base_url);
        if ($input->getOption('new-key') === true) {
            $output->writeln($this->access->createKey());
        }

        return Command::SUCCESS;
    }
}
