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
        $this->setDescription('与 Agent 进行交互式对话');
        $this->addArgument('agent', InputArgument::OPTIONAL, 'Agent 名称', 'default');
        $this->addOption('no-input', null, InputOption::VALUE_NONE, '无人值守模式');
        $this->addOption('model', 'm', InputOption::VALUE_OPTIONAL, '覆盖默认模型');
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
        $io->writeln('输入 /quit 或 /exit 退出');
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
                $io->warning("Agent 已挂起: {$e->getMessage()}");
                $io->note('可通过 resume API 恢复会话');
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
