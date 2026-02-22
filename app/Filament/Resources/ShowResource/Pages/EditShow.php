<?php

namespace App\Filament\Resources\ShowResource\Pages;

use App\Filament\Resources\ShowResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditShow extends EditRecord
{
    protected static string $resource = ShowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('capture_screenshot')
                ->label('Capture Screenshot')
                ->icon('heroicon-o-camera')
                ->color('info')
                ->disabled(fn () => $this->record->status !== 'live' || ! $this->record->source)
                ->tooltip(fn () => $this->record->status !== 'live'
                        ? 'Show must be live to capture screenshot'
                        : (! $this->record->source ? 'Show must have a source' : null)
                )
                ->action(function () {
                    if ($this->record->status !== 'live') {
                        Notification::make()
                            ->title('Cannot capture screenshot')
                            ->body('Show must be live to capture a screenshot.')
                            ->warning()
                            ->send();

                        return;
                    }

                    if (! $this->record->source) {
                        Notification::make()
                            ->title('Cannot capture screenshot')
                            ->body('Show must have a source assigned.')
                            ->warning()
                            ->send();

                        return;
                    }

                    try {
                        $screenshotPath = $this->record->captureScreenshot();
                        if ($screenshotPath) {
                            // Refresh the form to show the new thumbnail
                            $this->fillForm();

                            Notification::make()
                                ->title('Screenshot captured!')
                                ->body('Thumbnail has been updated for the show.')
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Screenshot capture failed')
                                ->body('Could not capture screenshot from the stream.')
                                ->warning()
                                ->send();
                        }
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Screenshot capture error')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Actions\Action::make('pause_vod_recording')
                ->label('Pause VOD Recording')
                ->icon('heroicon-o-pause')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Pause VOD Recording')
                ->modalDescription('Archive recording will be paused. Existing segments are preserved and recording will resume seamlessly when you continue. Use this during intermissions.')
                ->modalSubmitActionLabel('Pause Recording')
                ->visible(fn () => $this->record->status === 'live' && ! $this->record->vod_paused && $this->record->recordable)
                ->action(function () {
                    $this->record->pauseVodRecording();
                    Notification::make()
                        ->title('VOD recording paused')
                        ->body("Recording paused for '{$this->record->title}'. Intermission will not be included in VOD.")
                        ->warning()
                        ->send();
                }),
            Actions\Action::make('resume_vod_recording')
                ->label('Continue VOD Recording')
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(fn () => $this->record->status === 'live' && $this->record->vod_paused && $this->record->recordable)
                ->action(function () {
                    $this->record->resumeVodRecording();
                    Notification::make()
                        ->title('VOD recording resumed')
                        ->body("Recording resumed for '{$this->record->title}'.")
                        ->success()
                        ->send();
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
