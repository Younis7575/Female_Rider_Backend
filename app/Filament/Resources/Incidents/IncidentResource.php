<?php

namespace App\Filament\Resources\Incidents;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Filament\Resources\Trips\TripResource;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Services\Safety\IncidentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class IncidentResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = Incident::class;

    protected static ?string $navigationLabel = 'SOS & incidents';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'Safety & Support';

    protected static ?int $navigationSort = 1;

    protected static string $viewPermission = 'safety.view';

    protected static ?string $managePermission = 'safety.manage';

    protected static bool $allowCreate = false;

    protected static bool $allowEdit = false;

    public static function getNavigationBadge(): ?string
    {
        $n = Incident::query()->whereIn('status', ['open', 'acknowledged', 'investigating', 'escalated'])->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return Incident::query()->where('status', 'open')->where('severity', 'critical')->exists() ? 'danger' : 'warning';
    }

    public static function severityColor(string $state): string
    {
        return match ($state) {
            'critical' => 'danger', 'high' => 'warning', 'medium' => 'info', default => 'gray'
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Incident')->columns(4)->schema([
                TextEntry::make('reference')->weight('bold')->copyable(),
                TextEntry::make('type')->badge(),
                TextEntry::make('severity')->badge()->color(fn ($state) => self::severityColor($state)),
                TextEntry::make('status')->badge(),
                TextEntry::make('reporter.name')->label('Reporter')->helperText(fn ($record) => $record->reporter?->phone),
                TextEntry::make('againstUser.name')->label('Reported person')->placeholder('—'),
                TextEntry::make('trip.uuid')->label('Trip')->placeholder('—')->limit(13)->url(fn ($record) => $record->trip ? TripResource::getUrl('view', ['record' => $record->trip]) : null),
                TextEntry::make('location')->state(fn ($record) => $record->lat ? "{$record->lat}, {$record->lng}" : '—')->url(fn ($record) => $record->lat ? "https://maps.google.com/?q={$record->lat},{$record->lng}" : null, true),
                TextEntry::make('description')->columnSpanFull()->placeholder('—'),
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('acknowledged_at')->dateTime()->placeholder('Not acknowledged')
                    ->helperText(fn ($record) => $record->acknowledged_at ? 'Response time: '.$record->created_at->diffForHumans($record->acknowledged_at, true) : null),
                TextEntry::make('assignee.name')->label('Assigned to')->placeholder('Unassigned'),
                TextEntry::make('resolved_at')->dateTime()->placeholder('—'),
            ]),
            Section::make('Restricted investigation notes')->description('Visible to safety officers only. Encrypted at rest.')
                ->visible(fn () => auth('admin')->user()?->can('safety.manage'))
                ->schema([TextEntry::make('restricted_notes')->hiddenLabel()->placeholder('No notes')->prose()]),
            Section::make('Resolution')->schema([TextEntry::make('resolution')->hiddenLabel()->placeholder('Open')]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->poll('10s')->modifyQueryUsing(fn ($query) => $query->with('reporter', 'assignee'))->columns([
            TextColumn::make('created_at')->since()->sortable(),
            TextColumn::make('reference')->searchable()->weight('bold'),
            TextColumn::make('type')->badge()->color(fn ($state) => $state === 'sos' ? 'danger' : 'gray'),
            TextColumn::make('severity')->badge()->color(fn ($state) => self::severityColor($state)),
            TextColumn::make('reporter.name')->label('Reporter'),
            TextColumn::make('status')->badge()->color(fn ($state) => in_array($state, ['resolved', 'closed']) ? 'success' : ($state === 'open' ? 'danger' : 'warning')),
            TextColumn::make('assignee.name')->label('Assignee')->placeholder('—'),
        ])->filters([
            SelectFilter::make('status')->options(['open' => 'Open', 'acknowledged' => 'Acknowledged', 'investigating' => 'Investigating', 'escalated' => 'Escalated', 'resolved' => 'Resolved', 'closed' => 'Closed']),
            SelectFilter::make('severity')->options(['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low']),
            SelectFilter::make('type')->options(['sos' => 'SOS', 'safety_report' => 'Safety report', 'harassment' => 'Harassment', 'unsafe_driving' => 'Unsafe driving', 'accident' => 'Accident', 'vehicle_mismatch' => 'Vehicle mismatch', 'other' => 'Other']),
        ])->recordActions([ViewAction::make()]);
    }

    public static function viewHeaderActions(): array
    {
        $svc = fn () => app(IncidentService::class);
        $can = fn () => static::canManage();
        $open = fn ($record) => ! in_array($record->status, ['resolved', 'closed'], true) && $can();

        return [
            Action::make('acknowledge')->icon(Heroicon::OutlinedHandRaised)->color('warning')->visible(fn ($record) => $record->status === 'open' && $can())
                ->action(function ($record) use ($svc) {
                    $svc()->updateStatus(auth('admin')->user(), $record, 'acknowledged');
                    Notification::make()->title('Acknowledged')->success()->send();
                }),
            Action::make('assign')->icon(Heroicon::OutlinedUserPlus)->color('gray')->visible($open)
                ->schema([Select::make('admin_id')->label('Assign to')->options(fn () => AdminUser::role(['safety_officer', 'super_admin'], 'admin')->pluck('name', 'id'))->required()])
                ->action(function ($record, array $data) {
                    $record->update(['assigned_admin_id' => $data['admin_id']]);
                    AuditLog::record('incident.assigned', $record, [], ['assigned_admin_id' => $data['admin_id']]);
                }),
            Action::make('investigate')->icon(Heroicon::OutlinedMagnifyingGlass)->visible(fn ($record) => in_array($record->status, ['open', 'acknowledged'], true) && $can())
                ->action(fn ($record) => $svc()->updateStatus(auth('admin')->user(), $record, 'investigating')),
            Action::make('escalate')->icon(Heroicon::OutlinedArrowTrendingUp)->color('danger')->visible($open)
                ->requiresConfirmation()
                ->action(fn ($record) => $svc()->updateStatus(auth('admin')->user(), $record, 'escalated')),
            Action::make('notes')->label('Add restricted note')->icon(Heroicon::OutlinedLockClosed)->color('gray')->visible($can)
                ->schema([Textarea::make('note')->required()])
                ->action(function ($record, array $data) {
                    $record->update(['restricted_notes' => trim(($record->restricted_notes ? $record->restricted_notes."\n\n" : '').'['.now()->format('d M H:i').' '.auth('admin')->user()->name.'] '.$data['note'])]);
                    AuditLog::record('incident.note_added', $record);
                }),
            Action::make('resolve')->icon(Heroicon::OutlinedCheckCircle)->color('success')->visible($open)
                ->schema([Textarea::make('resolution')->required()->label('Outcome / action taken')])
                ->action(function ($record, array $data) use ($svc) {
                    $svc()->updateStatus(auth('admin')->user(), $record, 'resolved', $data['resolution']);
                    Notification::make()->title('Incident resolved')->success()->send();
                }),
        ];
    }

    public static function getRelations(): array
    {
        return [RelationManagers\EvidenceRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListIncidents::route('/'), 'view' => Pages\ViewIncident::route('/{record}')];
    }
}
