<?php

namespace App\Filament\Resources\Cities;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Models\City;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class CityResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = City::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 1;

    protected static string $viewPermission = 'zones.manage';

    protected static ?string $managePermission = 'zones.manage';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('City')->columns(3)->schema([
                TextInput::make('name')->required()->maxLength(80),
                TextInput::make('country_code')->required()->length(2)->default('PK'),
                TextInput::make('currency')->required()->length(3)->default('PKR'),
                TextInput::make('timezone')->required()->default('Asia/Karachi'),
                TextInput::make('center_lat')->label('Center latitude')->numeric()->required()->rules(['between:-90,90']),
                TextInput::make('center_lng')->label('Center longitude')->numeric()->required()->rules(['between:-180,180']),
                TextInput::make('emergency_number')->required()->default('15'),
                Toggle::make('is_active')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable()->weight('bold'),
            TextColumn::make('country_code')->badge(),
            TextColumn::make('currency'),
            TextColumn::make('zones_count')->counts('zones')->label('Zones'),
            TextColumn::make('emergency_number'),
            IconColumn::make('is_active')->boolean(),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCities::route('/'), 'create' => Pages\CreateCity::route('/create'), 'edit' => Pages\EditCity::route('/{record}/edit')];
    }
}
