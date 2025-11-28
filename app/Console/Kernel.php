protected function schedule(Schedule $schedule): void
{
    // Run daily at midnight
    $schedule->command('trials:expire')->daily();
}