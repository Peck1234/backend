<?php

namespace App\Console\Commands;

use App\Models\Nurse;
use Illuminate\Console\Command;

// Sets the first admin (afterwards admins change roles in the app):
//   php artisan nurse:make-admin Peck
class MakeNurseAdmin extends Command
{
    protected $signature = 'nurse:make-admin {username}';

    protected $description = 'Give an existing nurse account the admin role';

    public function handle()
    {
        $nurse = Nurse::where('username', $this->argument('username'))->first();
        if (!$nurse) {
            $this->error('No account with username "' . $this->argument('username') . '"');

            return 1;
        }

        $nurse->update(['role' => 'admin']);
        $this->info("OK: {$nurse->username} ({$nurse->full_name}) is now an admin");

        return 0;
    }
}
