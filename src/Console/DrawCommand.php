<?php

namespace ErnestDefoe\SecretSanta\Console;

use ErnestDefoe\SecretSanta\Draw;
use Flarum\Console\AbstractCommand;

/**
 * php flarum secret-santa:draw: draws every exchange whose sign-ups have
 * closed. Scheduled every five minutes; without a cron, the next visit to
 * the list or an exchange does the same (Draw::due).
 */
class DrawCommand extends AbstractCommand
{
    public function __construct(private readonly Draw $draw)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('secret-santa:draw')->setDescription('Draw Secret Santa exchanges whose sign-ups have closed.');
    }

    protected function fire(): int
    {
        $this->draw->due();

        return 0;
    }
}
