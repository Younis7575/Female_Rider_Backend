<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class AuditLogResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Audit logs';

    protected static string $viewPermission = 'audit.view';

    protected static ?string $managePermission = null;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('action')->badge(),
                TextEntry::make('actor')->state(fn (AuditLog $record) => $record->actorName().' ('.$record->actor_type.')'),
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('auditable_type')->label('Subject')->state(fn (AuditLog $record) => $record->auditable_type ? class_basename($record->auditable_type).' #'.$record->auditable_id : '—'),
                TextEntry::make('ip_address'),
                TextEntry::make('user_agent')->limit(60),
                KeyValueEntry::make('before')->state(fn (AuditLog $record) => collect($record->before ?? [])->map(fn ($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v))->all())->columnSpan(1),
                KeyValueEntry::make('after')->state(fn (AuditLog $record) => collect($record->after ?? [])->map(fn ($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v))->all())->columnSpan(1),
                KeyValueEntry::make('meta')->state(fn (AuditLog $record) => collect($record->meta ?? [])->map(fn ($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v))->all())->columnSpan(1),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('created_at')->dateTime('d M Y H:i:s')->sortable(),
            TextColumn::make('action')->badge()->searchable()->color(fn ($state) => str_contains($state, 'reject') || str_contains($state, 'suspend') || str_contains($state, 'sos') ? 'danger' : (str_contains($state, 'approve') ? 'success' : 'gray')),
            TextColumn::make('actor_type')->badge()->color('gray'),
            TextColumn::make('actor')->state(fn (AuditLog $record) => $record->actorName()),
            TextColumn::make('subject')->state(fn (AuditLog $record) => $record->auditable_type ? class_basename($record->auditable_type).' #'.$record->auditable_id : '—'),
            TextColumn::make('ip_address')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('actor_type')->options(['admin' => 'Admin', 'user' => 'User', 'system' => 'System']),
            Filter::make('date')->schema([DatePicker::make('from'), DatePicker::make('until')])
                ->query(fn ($query, array $data) => $query->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))->when($data['until'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))),
        ])->recordActions([ViewAction::make()]);
    }

    public static function viewHeaderActions(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAuditLogs::route('/'), 'view' => Pages\ViewAuditLog::route('/{record}')];
    }
}
