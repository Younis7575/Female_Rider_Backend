<?php

namespace App\Filament\Resources\ServiceZones;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Models\AuditLog;
use App\Models\ServiceZone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ServiceZoneResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = ServiceZone::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 2;

    protected static string $viewPermission = 'zones.manage';

    protected static ?string $managePermission = 'zones.manage';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Zone')->columns(2)->schema([
                Select::make('city_id')->relationship('city', 'name')->required()->preload(),
                TextInput::make('name')->required()->maxLength(120),
                TextInput::make('center_lat')->numeric()->required(),
                TextInput::make('center_lng')->numeric()->required(),
                Toggle::make('is_active')->default(true),
            ]),
            Section::make('Surge / demand pricing')->description('Multipliers are capped and can be disabled instantly (global kill-switch in System Settings).')->columns(3)->schema([
                Toggle::make('surge_enabled'),
                TextInput::make('surge_multiplier')->numeric()->minValue(1)->maxValue(3)->step(0.05)->default(1),
                TextInput::make('surge_cap')->numeric()->minValue(1)->maxValue(3)->step(0.05)->default(2),
            ]),
            Section::make('Service area polygon')->description('Vertices in order (latitude, longitude). Minimum 3 points.')->schema([
                Repeater::make('polygon')
                    ->schema([
                        TextInput::make('0')->label('Latitude')->numeric()->required(),
                        TextInput::make('1')->label('Longitude')->numeric()->required(),
                    ])->columns(2)->minItems(3)->defaultItems(4)->reorderable()
                    ->afterStateHydrated(fn (Repeater $component, $state) => $component->state(collect($state ?? [])->map(fn ($p) => ['0' => $p[0] ?? null, '1' => $p[1] ?? null])->all()))
                    ->dehydrateStateUsing(fn ($state) => collect($state)->map(fn ($p) => [(float) $p['0'], (float) $p['1']])->values()->all()),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->weight('bold'),
            TextColumn::make('city.name')->sortable(),
            IconColumn::make('is_active')->boolean(),
            IconColumn::make('surge_enabled')->boolean()->label('Surge'),
            TextColumn::make('surge_multiplier')->suffix('×'),
            TextColumn::make('surge_cap')->suffix('× cap'),
            TextColumn::make('updated_at')->since()->label('Updated'),
        ])->recordActions([
            Action::make('disableSurge')->label('Kill surge')->icon(Heroicon::OutlinedBolt)->color('danger')
                ->visible(fn (ServiceZone $record) => $record->surge_enabled && static::canManage())
                ->requiresConfirmation()
                ->action(function (ServiceZone $record) {
                    $record->update(['surge_enabled' => false, 'surge_multiplier' => 1]);
                    AuditLog::record('zone.surge_disabled', $record);
                    Notification::make()->title('Surge disabled')->success()->send();
                }),
            EditAction::make()->after(fn (ServiceZone $record) => AuditLog::record('zone.updated', $record, [], $record->only(['surge_enabled', 'surge_multiplier', 'surge_cap', 'is_active']))),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListServiceZones::route('/'), 'create' => Pages\CreateServiceZone::route('/create'), 'edit' => Pages\EditServiceZone::route('/{record}/edit')];
    }
}
