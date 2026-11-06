<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Console;

use App\Entity\Repository\SettingsRepository;
use Plugin\AzuracastSweepers\Service\DetectorAccess;
use Plugin\AzuracastSweepers\Service\DetectorClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prepares the detector account for the detector's setup and aligns its station access with the detector.
 *
 * Without options it prints the AzuraCast address; --new-key adds a new API key line (the unattended setup input).
 */
#[AsCommand(name: self::NAME, description: 'Prepare the Intro Detector account (used by bin/setup.sh).')]
final class DetectorAccountCommand extends Command
{
    public const string NAME = 'sweepers:detector-account';

    public function __construct(
        private readonly DetectorAccess $access,
        private readonly DetectorClient $detector,
        private readonly SettingsRepository $settings
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('new-key', null, InputOption::VALUE_NONE, 'Create an API key for the detector account');
        $this->addOption('sync', null, InputOption::VALUE_NONE, 'Grant access to the stations the detector enabled');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->access->ensureAccount();
        if ($input->getOption('sync') === true) {
            $stations = $this->detector->request('GET', '/api/v1/stations')['stations'] ?? [];
            $enabled = array_values(array_map(
                static fn(array $station): int => (int)$station['id'],
                array_filter(is_array($stations) ? $stations : [], static fn(mixed $s): bool
                    => is_array($s) && ($s['enabled'] ?? false) === true)
            ));
            $output->writeln(sprintf('Intro Detector access: %d station(s).', $this->access->sync($enabled)));

            return Command::SUCCESS;
        }
        $output->writeln((string)$this->settings->readSettings()->base_url);
        if ($input->getOption('new-key') === true) {
            $output->writeln($this->access->createKey());
        }

        return Command::SUCCESS;
    }
}
