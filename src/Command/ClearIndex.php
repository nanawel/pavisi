<?php

namespace App\Command;

use App\Service\ElasticsearchFileIndexer;
use App\Service\FileFinderBuilder;
use App\Service\VoskFileProcessor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class ClearIndex extends Command
{
    public function __construct(
        protected ElasticsearchFileIndexer $elasticsearchFileIndexer,
    ) {
        parent::__construct('app:index:clear');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Clear index')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $confirm = false;
        if ($input->getOption('no-interaction')) {
            $confirm = true;
        } else {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                "Are you really sure?\nThis action will delete all the documents from the index and cannot be reverted!> ",
                false
            );
            if ($helper->ask($input, $output, $question)) {
                $confirm = true;
            }
        }

        if ($confirm) {
            $this->elasticsearchFileIndexer->clearIndex();
            $output->writeln('Index has been deleted.');
        }

        return Command::SUCCESS;
    }
}
