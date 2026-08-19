<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use App\Jobs\ProcessOpenstreetmap;

#[Signature('app:command-Openstreetmap')]
#[Description('Start de ProcessOpenstreetmap job')]
class CommandOpenstreetmap extends Command
{
    //protected $signature = 'laptop:aanmelden';
    //protected $description = 'Drive the ProcessLaptopAanmelden job';
    /**
     * Execute the console command.
     */
    public function handle()
    {
        //
        return app(Dispatcher::class)->dispatch(new ProcessOpenstreetmap());
    }
}
