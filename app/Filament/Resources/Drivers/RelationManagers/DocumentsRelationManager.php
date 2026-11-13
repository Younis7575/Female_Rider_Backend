<?php

namespace App\Filament\Resources\Drivers\RelationManagers;

use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Services\Driver\DriverOnboardingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documents';

    public function table(Table $table): Table
    {
        $canReview = fn () => (bool) auth('admin')->user()?->can('drivers.review');
        $canView = fn () => (bool) auth('admin')->user()?->can('documents.view');

        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('type')->formatStateUsing(fn ($state) => ucwords(str_replace('_', ' ', $state)))->weight('bold')
                ->description(fn (DriverDocument $record) => in_array($record->type, DriverProfile::REQUIRED_DOCUMENTS, true) ? 'Required' : 'Optional'),
            TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                'approved' => 'success', 'rejected' => 'danger', default => 'warning'
            }),
            TextColumn::make('expiry_date')->date()->placeholder('—')->color(fn ($record) => $record->expiry_date?->isPast() ? 'danger' : null),
            TextColumn::make('rejection_reason')->limit(40)->placeholder('—'),
            TextColumn::make('created_at')->label('Uploaded')->since(),
            TextColumn::make('reviewer.name')->placeholder('—'),
            IconColumn::make('latest')->label('Latest')->boolean()->state(fn (DriverDocument $record) => DriverDocument::query()->where('driver_profile_id', $record->driver_profile_id)->where('type', $record->type)->max('id') === $record->id),
        ])->filters([SelectFilter::make('status')->options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'])])
            ->recordActions([
                Action::make('open')->icon(Heroicon::OutlinedEye)->color('gray')->visible($canView)
                    ->url(fn (DriverDocument $record) => route('admin.secure.file', ['type' => 'driver-document', 'id' => $record->id]), true),
                Action::make('approve')->icon(Heroicon::OutlinedCheck)->color('success')->visible(fn ($record) => $record->status !== 'approved' && $canReview())
                    ->action(function (DriverDocument $record) {
                        app(DriverOnboardingService::class)->reviewDocument(auth('admin')->user(), $record, true);
                        Notification::make()->title('Document approved')->success()->send();
                    }),
                Action::make('reject')->icon(Heroicon::OutlinedXMark)->color('danger')->visible(fn ($record) => $record->status !== 'rejected' && $canReview())
                    ->schema([Textarea::make('reason')->required()->label('Reason (shown to driver)')])
                    ->action(function (DriverDocument $record, array $data) {
                        app(DriverOnboardingService::class)->reviewDocument(auth('admin')->user(), $record, false, $data['reason']);
                        Notification::make()->title('Document rejected')->send();
                    }),
            ]);
    }
}
