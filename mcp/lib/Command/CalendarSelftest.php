<?php
declare(strict_types=1);
namespace OCA\Mcp\Command;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\L10n\UserL10n;
use OCA\Mcp\Service\Calendar\CalendarSelftestMessages as Messages;
use OCA\Mcp\Service\Calendar\CalendarSelftestService;
use OCP\IUserManager;
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
     * @param UserL10n|null $l10n language resolver
     * @param IUserManager|null $userManager user lookup
     */
    public function __construct(
        private CalendarSelftestService $service,
        private ?UserL10n $l10n = null,
        private ?IUserManager $userManager = null,
    ) {
        parent::__construct('mcp:calendar-selftest');
    }

    /** @return void declares the command ; optional diagnostic only */
    protected function configure(): void {
        $this->setDescription(Messages::DESCRIPTION)
            ->addArgument('uid', InputArgument::OPTIONAL, Messages::UID)
            ->addOption('attendee-uid', null, InputOption::VALUE_REQUIRED, Messages::ATTENDEE)
            ->addOption('shared-calendar', null, InputOption::VALUE_REQUIRED, Messages::SHARED)
            ->addOption('acl-probe-user', null, InputOption::VALUE_REQUIRED, Messages::ACL);
    }

    /** @return int zero only when every check passed; it never changes what is exposed */
    protected function execute(InputInterface $input, OutputInterface $output): int {
        $uid = $input->getArgument('uid');
        if (!is_string($uid) || $uid === '') {
            $output->writeln(Messages::UID);
            return self::INVALID;
        }
        if ($this->l10n !== null && $this->userManager !== null) {
            $user = $this->userManager->get($uid);
            if ($user !== null) {
                Translator::use($this->l10n->forUser($user));
            }
        }
        try {
            $options = [];
            foreach (['attendee-uid', 'shared-calendar', 'acl-probe-user'] as $option) {
                $value = $input->getOption($option);
                if (is_string($value) && $value !== '') { $options[$option] = $value; }
            }
            $passed = $this->service->run($uid, $options, static function (array $step) use ($output): void {
                $output->writeln(OutputFormatter::escape(Messages::line($step)));
            });
            $output->writeln(Messages::summary());
            return $passed ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable) {
            $output->writeln(Messages::failed());
            return self::FAILURE;
        } finally {
            Translator::reset();
        }
    }
}
