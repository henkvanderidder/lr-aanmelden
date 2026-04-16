<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use App\Jobs\ProcessLaptopAanmelden;

#[Signature('app:command-laptop-aanmelden')]
#[Description('Start de ProcessLaptopAanmelden job')]
class CommandLaptopAanmelden extends Command
{
    //protected $signature = 'laptop:aanmelden';
    //protected $description = 'Drive the ProcessLaptopAanmelden job';
    /**
     * Execute the console command.
     */
    public function handle()
    {
        //
        return app(Dispatcher::class)->dispatch(new ProcessLaptopAanmelden());
    }
}
