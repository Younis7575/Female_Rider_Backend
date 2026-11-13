<?php

namespace App\Filament\Resources\SystemSettings;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class SystemSettingResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = SystemSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'System settings';

    protected static string $viewPermission = 'settings.manage';

    protected static ?string $managePermission = 'settings.manage';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(2)->schema([
                TextInput::make('key')->required()->regex('/^[a-z0-9_.]+$/')->unique(ignoreRecord: true)->disabledOn('edit'),
                Select::make('type')->options(['string' => 'Text', 'integer' => 'Number', 'boolean' => 'On/Off', 'json' => 'JSON'])->required()->default('string'),
                TextInput::make('group')->required()->default('general'),
                TextInput::make('description'),
                Textarea::make('value')->label('Value (booleans: true/false; JSON allowed)')->columnSpanFull()
                    ->formatStateUsing(fn ($state) => is_bool($state) ? ($state ? 'true' : 'false') : (is_array($state) ? json_encode($state) : (string) $state))
                    ->dehydrateStateUsing(function ($state, $get) {
                        return match ($get('type')) {
                            'boolean' => in_array(strtolower(trim((string) $state)), ['1', 'true', 'yes', 'on'], true),
                            'integer' => (int) $state,
                            'json' => json_decode((string) $state, true),
                            default => (string) $state,
                        };
                    }),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('group')->columns([
            TextColumn::make('key')->searchable()->fontFamily('mono')->weight('bold')->description(fn ($record) => $record->description),
            TextColumn::make('group')->badge(),
            TextColumn::make('value')->formatStateUsing(fn ($state) => is_bool($state) ? ($state ? 'ON' : 'OFF') : (is_array($state) ? json_encode($state) : (string) $state))->badge()->color(fn ($state) => $state === true ? 'success' : ($state === false ? 'gray' : 'info')),
            TextColumn::make('updated_at')->since(),
        ])->filters([SelectFilter::make('group')->options(fn () => SystemSetting::query()->distinct()->pluck('group', 'group')->all())])
            ->recordActions([EditAction::make()->before(fn ($record) => session()->put('setting_before', $record->value))
                ->after(fn ($record) => AuditLog::record('settings.updated', $record, ['value' => session()->pull('setting_before')], ['value' => $record->value], ['key' => $record->key]))]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListSystemSettings::route('/'), 'create' => Pages\CreateSystemSetting::route('/create'), 'edit' => Pages\EditSystemSetting::route('/{record}/edit')];
    }
}
