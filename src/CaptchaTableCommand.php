<?php namespace InfinityNext\BrennanCaptcha;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;

class CaptchaTableCommand extends Command
{
	
	/**
	 * The console command name.
	 *
	 * @var string
	 */
	protected $name = 'captcha:table';
	
	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Create a migration for the Brennan CAPTCHA database table';
	
	/**
	 * Execute the console command.
	 *
	 * @return void
	 */
	public function fire()
	{
		$fullPath = $this->createBaseMigration();
		
		file_put_contents($fullPath, $this->getMigrationStub());
		
		$this->info('Migration created successfully!');
		
		$this->laravel['composer']->dumpAutoloads();
	}
	
	/**
	 * Create a base migration file for the reminders.
	 *
	 * @return string
	 */
	protected function createBaseMigration()
	{
		$name = 'add_brennan_captcha_table';
		
		$path = $this->laravel['path.database'] . '/migrations';
		
		return $this->laravel['migration.creator']->create($name, $path);
	}
	
	/**
	 * Get the contents of the reminder migration stub.
	 *
	 * @return string
	 */
	protected function getMigrationStub()
	{
		$stub = file_get_contents(__DIR__ . '/stubs/migration.stub');
		
		return str_replace('captcha_table', $this->argument('table'), $stub);
	}
	
	/**
	 * Get the console command arguments.
	 *
	 * @return array
	 */
	protected function getArguments()
	{
		return [
			['table', InputArgument::REQUIRED, 'The name of your new captcha table.'],
		];
	}
	
}