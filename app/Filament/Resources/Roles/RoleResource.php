<?php

namespace App\Filament\Resources\Roles;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Models\AdminUser;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;
use UnitEnum;

class RoleResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Roles & permissions';

    protected static string $viewPermission = 'admins.manage';

    protected static ?string $managePermission = 'admins.manage';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('guard_name', 'admin');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->schema([
                TextInput::make('name')->required()->regex('/^[a-z_]+$/')->unique(ignoreRecord: true)->disabled(fn ($record) => $record?->name === 'super_admin'),
                Hidden::make('guard_name')->default('admin'),
                CheckboxList::make('permissions')->relationship('permissions', 'name', fn ($query) => $query->where('guard_name', 'admin'))
                    ->columns(3)->bulkToggleable()->searchable()
                    ->disabled(fn ($record) => $record?->name === 'super_admin'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->weight('bold')->formatStateUsing(fn ($state) => str($state)->replace('_', ' ')->title()),
            TextColumn::make('permissions_count')->counts('permissions')->label('Permissions'),
            TextColumn::make('users_count')->label('Staff')->state(fn (Role $record) => AdminUser::role($record->name, 'admin')->count()),
        ])->recordActions([EditAction::make()->after(fn (Role $record) => AuditLog::record('role.updated', $record, [], ['permissions' => $record->permissions->pluck('name')->all()]))]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListRoles::route('/'), 'create' => Pages\CreateRole::route('/create'), 'edit' => Pages\EditRole::route('/{record}/edit')];
    }
}
