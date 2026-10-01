<?php
declare(strict_types=1);
namespace OCA\Mcp\Command;

use OCA\Mcp\Service\Calendar\CalendarSelftestMessages as Messages;
use OCA\Mcp\Service\Calendar\CalendarSelftestService;
use OCA\Mcp\Tools\Calendar\CalendarWriteGate;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;

/** Thin occ adapter: all proof, ownership and cleanup live in the service. */
final class CalendarSelftest extends Command {
    /**
     * @param CalendarSelftestService $service real server proof
     * @param CalendarWriteGate $gate revocation
     */
    public function __construct(private CalendarSelftestService $service, private CalendarWriteGate $gate) {
        parent::__construct('mcp:calendar-selftest');
    }

    /** @return void declares the command and explicit opt-in side effects */
    protected function configure(): void {
        $this->setDescription(Messages::DESCRIPTION)
            ->addArgument('uid', InputArgument::OPTIONAL, Messages::UID)
            ->addOption('attendee-uid', null, InputOption::VALUE_REQUIRED, Messages::ATTENDEE)
            ->addOption('shared-calendar', null, InputOption::VALUE_REQUIRED, Messages::SHARED)
            ->addOption('acl-probe-user', null, InputOption::VALUE_REQUIRED, Messages::ACL)
            ->addOption('no-enable', null, InputOption::VALUE_NONE, Messages::NO_ENABLE)
            ->addOption('revoke', null, InputOption::VALUE_NONE, Messages::REVOKE);
    }

    /** @return int zero only for a successful proof or revocation; failures keep writes hidden */
    protected function execute(InputInterface $input, OutputInterface $output): int {
        if ($input->getOption('revoke')) {
            $this->gate->revoke();
            $output->writeln(Messages::REVOKED);
            return self::SUCCESS;
        }
        $uid = $input->getArgument('uid');
        if (!is_string($uid) || $uid === '') {
            $output->writeln(Messages::UID);
            return self::INVALID;
        }
        $options = ['no-enable' => (bool)$input->getOption('no-enable')];
        foreach (['attendee-uid', 'shared-calendar', 'acl-probe-user'] as $option) {
            $value = $input->getOption($option);
            if (is_string($value) && $value !== '') { $options[$option] = $value; }
        }
        try {
            $passed = $this->service->run($uid, $options, static function (array $step) use ($output): void {
                $output->writeln(OutputFormatter::escape(Messages::line($step)));
            });
        } catch (\Throwable) {
            $this->gate->revoke();
            $output->writeln(Messages::FAILED);
            return self::FAILURE;
        }
        $output->writeln(Messages::SUMMARY);
        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
