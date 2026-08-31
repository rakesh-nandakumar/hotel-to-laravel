<?php

namespace App\Console\Commands;

use App\Models\CentralAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password as promptPassword;
use function Laravel\Prompts\text;

/**
 * Creates a platform operator — the only supported way to get a master control
 * login on a real deployment, since Database\Seeders\CentralAdminSeeder
 * deliberately skips production (it seeds a well-known demo password).
 *
 * Without this a fresh production install has no `central_admins` row at all,
 * and the panel that provisions every tenant is unreachable.
 */
class CreateCentralAdmin extends Command
{
    protected $signature = 'central:create-admin
                            {--name= : Operator display name}
                            {--email= : Login email (must be unique)}
                            {--password= : Login password (min 8 chars; prompted securely if omitted)}';

    protected $description = 'Create a platform operator who can sign in to the master control panel';

    public function handle(): int
    {
        $name = $this->option('name') ?: text('Name', required: true);
        $email = $this->option('email') ?: text('Email', required: true);
        $password = $this->option('password') ?: promptPassword('Password', required: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:150'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:central_admins,email'],
                'password' => ['required', 'string', 'min:8'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        // 'password' is a hashed cast on the model — no manual Hash::make here.
        $admin = CentralAdmin::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_active' => true,
        ]);

        $this->components->info("Platform operator [{$admin->email}] created.");

        $base = config('tenancy.base_domain') ?: (string) parse_url(config('app.url'), PHP_URL_HOST);
        $centralHost = config('tenancy.central_prefix').'.'.$base;
        $this->components->bulletList(["Sign in at https://{$centralHost}/login"]);

        return self::SUCCESS;
    }
}
