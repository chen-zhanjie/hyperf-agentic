<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Command;

use ChenZhanjie\Agentic\Agentic;
use ChenZhanjie\Agentic\Exception\AgentSuspendedException;
use ChenZhanjie\Agentic\Resolver\CliHumanInputResolver;
use ChenZhanjie\Agentic\Resolver\NullHumanInputResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Interactive agent chat command — Layer 5 entry point.
 */
class AgentChatCommand extends Command
{
    protected static string $defaultName = 'agent:chat';

    public function __construct(
        private readonly Agentic $agentic,
    ) {
        parent::__construct('agent:chat');
    }

    protected function configure(): void
    {
        $this->setDescription('Interactive agent chat session');
        $this->addArgument('agent', InputArgument::OPTIONAL, 'Agent name', 'default');
        $this->addOption('no-input', null, InputOption::VALUE_NONE, 'Unattended mode (no human input)');
        $this->addOption('model', 'm', InputOption::VALUE_OPTIONAL, 'Override default model');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agentName = $input->getArgument('agent');
        $io = new SymfonyStyle($input, $output);

        // Wire up the appropriate resolver
        $resolver = $input->getOption('no-input')
            ? new NullHumanInputResolver()
            : new CliHumanInputResolver($io);
        $this->agentic->setHumanInputResolver($resolver);

        $io->title("Agentic Chat — Agent: {$agentName}");
        $io->writeln('Type /quit or /exit to leave');
        $io->writeln('');

        $messages = [];

        while (true) {
            $message = $io->ask('You');

            if ($message === null || $message === '/quit' || $message === '/exit') {
                break;
            }

            if (trim($message) === '') {
                continue;
            }

            $messages[] = ['role' => 'user', 'content' => $message];

            $options = [];
            if ($model = $input->getOption('model')) {
                $options['model_override'] = $model;
            }

            try {
                $result = $this->agentic->run($agentName, $messages, $options);
            } catch (AgentSuspendedException $e) {
                $io->warning("Agent suspended: {$e->getMessage()}");
                $io->note('Use the resume API to recover this session');
                break;
            }

            $messages[] = ['role' => 'assistant', 'content' => $result->content];

            $output->writeln("<info>{$result->content}</info>");
            $output->writeln('');
        }

        $io->success('Bye!');
        return Command::SUCCESS;
    }
}
