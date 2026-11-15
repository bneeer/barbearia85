<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->validateExistingColumns();

        Schema::table('barbershop_user', function (Blueprint $table) {
            if (! Schema::hasColumn('barbershop_user', 'salary_base_cents')) {
                $table->unsignedBigInteger('salary_base_cents')->default(0);
            }
        });

        Schema::table('services', function (Blueprint $table) {
            if (! Schema::hasColumn('services', 'commission_percent')) {
                $table->decimal('commission_percent', 5, 2)->default(0);
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'average_cost_milli_cents')) {
                $table->unsignedBigInteger('average_cost_milli_cents')->nullable();
            }
        });

        Schema::table('supplies', function (Blueprint $table) {
            if (! Schema::hasColumn('supplies', 'average_cost_milli_cents')) {
                $table->unsignedBigInteger('average_cost_milli_cents')->nullable();
            }
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_movements', 'unit_cost_milli_cents')) {
                $table->unsignedBigInteger('unit_cost_milli_cents')->nullable();
            }
            if (! Schema::hasColumn('stock_movements', 'cost_total_cents')) {
                $table->unsignedBigInteger('cost_total_cents')->nullable();
            }
        });

        Schema::table('appointment_checkouts', function (Blueprint $table) {
            if (! Schema::hasColumn('appointment_checkouts', 'product_cost_cents')) {
                $table->unsignedBigInteger('product_cost_cents')->nullable();
            }
            if (! Schema::hasColumn('appointment_checkouts', 'supply_cost_cents')) {
                $table->unsignedBigInteger('supply_cost_cents')->nullable();
            }
            if (! Schema::hasColumn('appointment_checkouts', 'commission_cents')) {
                $table->unsignedBigInteger('commission_cents')->nullable();
            }
        });

        if (Schema::hasTable('service_supplies')) {
            $this->validateExistingTable('service_supplies', ['service_id', 'supply_id', 'quantity'], [
                [['service_id', 'supply_id'], true],
            ], [
                ['service_id', 'services', 'cascade'],
                ['supply_id', 'supplies', 'restrict'],
            ]);
        } else {
            Schema::create('service_supplies', function (Blueprint $table) {
                $table->foreignId('service_id')->constrained()->cascadeOnDelete();
                $table->foreignId('supply_id')->constrained()->restrictOnDelete();
                $table->decimal('quantity', 12, 3);
                $table->primary(['service_id', 'supply_id']);
            });
        }

        if (Schema::hasTable('barber_salary_rates')) {
            $this->validateExistingTable('barber_salary_rates', [
                'id', 'barbershop_id', 'user_id', 'salary_base_cents',
                'effective_from', 'effective_until', 'created_at', 'updated_at',
            ], [
                [['id'], true],
                [['barbershop_id', 'user_id', 'effective_from'], true],
            ], [
                ['barbershop_id', 'barbershops', 'cascade'],
                ['user_id', 'users', 'cascade'],
            ]);
            if (! Schema::hasIndex('barber_salary_rates', ['barbershop_id', 'user_id', 'effective_from', 'effective_until'])) {
                Schema::table('barber_salary_rates', function (Blueprint $table) {
                    $table->index(['barbershop_id', 'user_id', 'effective_from', 'effective_until'], 'barber_salary_rates_period_index');
                });
            }
        } else {
            Schema::create('barber_salary_rates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('barbershop_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('salary_base_cents');
                $table->date('effective_from');
                $table->date('effective_until')->nullable();
                $table->timestamps();
                $table->unique(['barbershop_id', 'user_id', 'effective_from']);
                $table->index(['barbershop_id', 'user_id', 'effective_from', 'effective_until'], 'barber_salary_rates_period_index');
            });
        }

        if (Schema::hasTable('expense_recurrences')) {
            $this->validateExistingTable('expense_recurrences', [
                'id', 'barbershop_id', 'created_by', 'title', 'category', 'amount_cents',
                'due_day', 'starts_on', 'ends_on', 'active', 'created_at', 'updated_at',
            ], [
                [['id'], true],
                [['barbershop_id', 'active', 'starts_on'], false],
            ], [
                ['barbershop_id', 'barbershops', 'restrict'],
                ['created_by', 'users', 'restrict'],
            ]);
        } else {
            Schema::create('expense_recurrences', function (Blueprint $table) {
                $table->id();
                $table->foreignId('barbershop_id')->constrained()->restrictOnDelete();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->string('title', 255);
                $table->string('category', 100);
                $table->unsignedBigInteger('amount_cents');
                $table->unsignedTinyInteger('due_day');
                $table->date('starts_on');
                $table->date('ends_on')->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->index(['barbershop_id', 'active', 'starts_on']);
            });
        }

        if (Schema::hasTable('expenses')) {
            $this->validateExistingTable('expenses', [
                'id', 'barbershop_id', 'recurrence_id', 'created_by', 'title',
                'category', 'amount_cents', 'competence_date', 'due_date', 'created_at', 'updated_at',
            ], [
                [['id'], true],
                [['recurrence_id', 'competence_date'], true],
                [['barbershop_id', 'competence_date'], false],
                [['barbershop_id', 'due_date'], false],
            ], [
                ['barbershop_id', 'barbershops', 'restrict'],
                ['recurrence_id', 'expense_recurrences', 'set null'],
                ['created_by', 'users', 'restrict'],
            ]);
        } else {
            Schema::create('expenses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('barbershop_id')->constrained()->restrictOnDelete();
                $table->foreignId('recurrence_id')->nullable()->constrained('expense_recurrences')->nullOnDelete();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->string('title', 255);
                $table->string('category', 100);
                $table->unsignedBigInteger('amount_cents');
                $table->date('competence_date');
                $table->date('due_date');
                $table->timestamps();
                $table->unique(['recurrence_id', 'competence_date']);
                $table->index(['barbershop_id', 'competence_date']);
                $table->index(['barbershop_id', 'due_date']);
            });
        }

        if (Schema::hasTable('expense_payments')) {
            $this->validateExistingTable('expense_payments', [
                'id', 'expense_id', 'user_id', 'cash_register_id', 'amount_cents',
                'payment_method', 'paid_at', 'notes', 'created_at', 'updated_at',
            ], [
                [['id'], true],
                [['expense_id', 'paid_at'], false],
                [['cash_register_id', 'payment_method'], false],
            ], [
                ['expense_id', 'expenses', 'restrict'],
                ['user_id', 'users', 'restrict'],
                ['cash_register_id', 'cash_registers', 'set null'],
            ]);
        } else {
            Schema::create('expense_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('expense_id')->constrained()->restrictOnDelete();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->foreignId('cash_register_id')->nullable()->constrained()->nullOnDelete();
                $table->unsignedBigInteger('amount_cents');
                $table->string('payment_method', 20);
                $table->timestamp('paid_at');
                $table->string('notes', 500)->nullable();
                $table->timestamps();
                $table->index(['expense_id', 'paid_at']);
                $table->index(['cash_register_id', 'payment_method']);
            });
        }
    }

    private function validateExistingColumns(): void
    {
        $columns = [
            'barbershop_user' => ['salary_base_cents' => ['bigint unsigned', false, '0']],
            'services' => ['commission_percent' => ['decimal(5,2)', false, '0']],
            'products' => ['average_cost_milli_cents' => ['bigint unsigned', true, null]],
            'supplies' => ['average_cost_milli_cents' => ['bigint unsigned', true, null]],
            'stock_movements' => [
                'unit_cost_milli_cents' => ['bigint unsigned', true, null],
                'cost_total_cents' => ['bigint unsigned', true, null],
            ],
            'appointment_checkouts' => [
                'product_cost_cents' => ['bigint unsigned', true, null],
                'supply_cost_cents' => ['bigint unsigned', true, null],
                'commission_cents' => ['bigint unsigned', true, null],
            ],
        ];

        foreach ($columns as $table => $expectedColumns) {
            $existing = collect(Schema::getColumns($table))->keyBy('name');
            foreach ($expectedColumns as $name => [$type, $nullable, $default]) {
                $column = $existing->get($name);
                if (! $column) {
                    continue;
                }

                $wrongType = Schema::getConnection()->getDriverName() === 'mysql'
                    && strtolower($column['type']) !== $type;
                $actualDefault = $column['default'] === null ? null : trim((string) $column['default'], "'");
                $wrongDefault = $default === null
                    ? $actualDefault !== null
                    : ! is_numeric($actualDefault) || (float) $actualDefault !== (float) $default;
                if ($wrongType || $column['nullable'] !== $nullable || $wrongDefault) {
                    throw new RuntimeException("A coluna existente {$table}.{$name} é incompatível com a migration financeira. Revise seu esquema antes de continuar.");
                }
            }
        }
    }

    private function validateExistingTable(string $table, array $columns, array $indexes, array $foreignKeys): void
    {
        if (! Schema::hasColumns($table, $columns)) {
            throw new RuntimeException("A tabela existente {$table} está incompleta. Revise seu esquema antes de continuar a migration financeira.");
        }

        $existingIndexes = collect(Schema::getIndexes($table));
        foreach ($indexes as [$indexColumns, $unique]) {
            if (! $existingIndexes->contains(fn ($index) => $index['columns'] === $indexColumns && (! $unique || $index['unique']))) {
                throw new RuntimeException("A tabela existente {$table} não possui um índice obrigatório. Revise seu esquema antes de continuar a migration financeira.");
            }
        }

        $existingKeys = collect(Schema::getForeignKeys($table));
        foreach ($foreignKeys as [$column, $foreignTable, $onDelete]) {
            if (! $existingKeys->contains(fn ($key) => $key['columns'] === [$column]
                && $key['foreign_table'] === $foreignTable
                && $key['foreign_columns'] === ['id']
                && strtolower($key['on_delete']) === $onDelete)) {
                throw new RuntimeException("A tabela existente {$table} não possui a chave estrangeira esperada para {$column}. Revise seu esquema antes de continuar a migration financeira.");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_payments');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_recurrences');
        Schema::dropIfExists('barber_salary_rates');
        Schema::dropIfExists('service_supplies');

        Schema::table('appointment_checkouts', function (Blueprint $table) {
            $table->dropColumn(['product_cost_cents', 'supply_cost_cents', 'commission_cents']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn(['unit_cost_milli_cents', 'cost_total_cents']);
        });

        Schema::table('supplies', function (Blueprint $table) {
            $table->dropColumn('average_cost_milli_cents');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('average_cost_milli_cents');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('commission_percent');
        });

        Schema::table('barbershop_user', function (Blueprint $table) {
            $table->dropColumn('salary_base_cents');
        });
    }
};
