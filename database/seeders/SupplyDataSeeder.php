<?php

namespace Database\Seeders;

use App\Models\Barbershop;
use App\Models\Scopes\BarbershopScope;
use App\Models\Supply;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SupplyDataSeeder extends Seeder
{
    public function run(): void
    {
        $shops = Barbershop::all();

        if ($shops->isEmpty()) {
            throw new RuntimeException('Cadastre uma unidade antes de popular os insumos.');
        }

        $created = DB::transaction(function () use ($shops): int {
            $created = 0;

            foreach ($shops as $shop) {
                foreach ($this->supplies() as [$name, $unit, $description]) {
                    $supply = Supply::withoutGlobalScope(BarbershopScope::class)->withTrashed()->firstOrCreate(
                        ['barbershop_id' => $shop->id, 'name' => $name],
                        [
                            'unit' => $unit,
                            'description' => $description,
                            'stock_quantity' => 0,
                            'min_stock' => 0,
                        ],
                    );
                    $created += (int) $supply->wasRecentlyCreated;
                }
            }

            return $created;
        });

        $this->command?->info("{$created} insumos cadastrados em {$shops->count()} unidade(s). Cadastros existentes preservados, sem entradas de estoque ou custos ficticios.");
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function supplies(): array
    {
        return [
            ['Lâminas de barbear', 'un', 'Descartável / biossegurança. Uso único por cliente; partir ao meio para encaixe no navalhete e descartar em coletor de perfurocortantes. Estoque contado por lâmina inteira.'],
            ['Coletor de perfurocortantes', 'un', 'Descartável / biossegurança. Caixa apropriada, como Descarpack, para descarte de lâminas. Substituir conforme o limite de enchimento e as instruções do fabricante.'],
            ['Gola higiênica', 'un', 'Descartável / biossegurança. Fita de papel elástico colocada no pescoço antes da capa para impedir contato direto com a pele e entrada de fios na roupa. Contar por tira de uso individual.'],
            ['Luvas descartáveis de nitrilo', 'un', 'Descartável / biossegurança. Para barbear, procedimentos químicos e limpeza; trocar entre clientes e tarefas. Estoque contado por luva, não por par.'],
            ['Luvas descartáveis de látex', 'un', 'Descartável / biossegurança. Para barbear, procedimentos químicos e limpeza; observar alergias ao látex. Trocar entre clientes e tarefas; contar por luva.'],
            ['Luvas descartáveis de vinil', 'un', 'Descartável / biossegurança. Para tarefas compatíveis com a proteção indicada pelo fabricante. Trocar entre clientes e tarefas; contar por luva.'],
            ['Toalhas descartáveis de TNT', 'un', 'Descartável / biossegurança. Para secar cabelos e rostos em substituição às toalhas de pano; descartar após o uso em cada cliente.'],
            ['Toalhas descartáveis de papel', 'un', 'Descartável / biossegurança. Papel de alta absorção para secar cabelos e rostos; contar por folha e descartar após o uso.'],
            ['Papel alumínio', 'g', 'Descartável / biossegurança. Para luzes, platinados e procedimentos térmicos compatíveis; descartar a porção usada após o atendimento. Controlar por peso.'],
            ['Toucas plásticas descartáveis', 'un', 'Descartável / biossegurança. Para procedimentos químicos e hidratações térmicas compatíveis; descartar após o atendimento.'],
            ['Cera depilatória', 'g', 'Consumível de uso único / perecível. Para depilação de áreas permitidas pelo fabricante; descartar a porção usada, não reutilizar e observar validade e contraindicações.'],
            ['Palitos de madeira', 'un', 'Descartável / biossegurança. Para aplicação de cera e limpeza no design de sobrancelhas; não reutilizar nem reintroduzir palito usado na cera.'],
            ['Algodão', 'g', 'Descartável / biossegurança. Para limpar excessos de produtos e secar pequenas áreas; descartar a porção usada após o atendimento.'],
            ['Lenços de papel', 'un', 'Descartável / biossegurança. Para limpeza, secagem de pequenas áreas e finalização; contar por lenço e descartar após o uso.'],
            ['Cotonetes', 'un', 'Descartável / biossegurança. Para limpeza localizada de excessos de produtos e auxílio na finalização; descartar após o uso.'],
            ['Shampoo de limpeza profunda', 'ml', 'Perecível / lavatório. Limpeza profunda de cabelos e couro cabeludo conforme orientação do fabricante; observar validade e prazo após abertura.'],
            ['Shampoo neutro', 'ml', 'Perecível / lavatório. Limpeza dos cabelos e couro cabeludo; observar validade e prazo após abertura.'],
            ['Condicionador', 'ml', 'Perecível / lavatório. Condicionamento dos fios após a lavagem; observar validade e prazo após abertura.'],
            ['Máscara de hidratação', 'g', 'Perecível / lavatório. Hidratação dos fios conforme tempo de ação do fabricante; observar validade e prazo após abertura.'],
            ['Espuma de barbear', 'ml', 'Perecível / barbearia clássica. Facilita o deslizamento da lâmina durante o barbear; observar validade e prazo após abertura.'],
            ['Gel de barbear', 'ml', 'Perecível / barbearia clássica. Lubrificação e deslizamento da lâmina durante o barbear; observar validade e prazo após abertura.'],
            ['Óleo pré-barba', 'ml', 'Perecível / barbearia clássica. Preparação da pele e dos fios antes do barbear; observar validade e prazo após abertura.'],
            ['Loção pós-barba', 'ml', 'Perecível / barbearia clássica. Finalização após o barbear conforme indicação do fabricante; observar validade e prazo após abertura.'],
            ['Bálsamo pós-barba', 'ml', 'Perecível / barbearia clássica. Cuidado e hidratação após o barbear; observar validade e prazo após abertura.'],
            ['Pomada modeladora matte', 'g', 'Perecível / finalização capilar. Modelagem com acabamento sem brilho; observar validade e prazo após abertura.'],
            ['Pomada modeladora com brilho', 'g', 'Perecível / finalização capilar. Modelagem com acabamento brilhante; observar validade e prazo após abertura.'],
            ['Pomada modeladora efeito teia', 'g', 'Perecível / finalização capilar. Modelagem e texturização dos fios com efeito teia; observar validade e prazo após abertura.'],
            ['Cera modeladora capilar', 'g', 'Perecível / finalização capilar. Modelagem e definição dos cabelos; observar validade e prazo após abertura.'],
            ['Gel capilar', 'g', 'Perecível / finalização capilar. Fixação e modelagem dos cabelos; observar validade e prazo após abertura.'],
            ['Spray fixador', 'ml', 'Perecível / finalização capilar. Fixação do penteado; observar validade, armazenamento e precauções do fabricante.'],
            ['Óleo para barba', 'ml', 'Perecível / finalização. Hidratação, maciez e alinhamento dos fios da barba; observar validade e prazo após abertura.'],
            ['Pó descolorante', 'g', 'Perecível / produtos químicos. Para descoloração, luzes e platinados; seguir instruções de mistura, testes e precauções do fabricante. Observar validade.'],
            ['Água oxigenada', 'ml', 'Perecível / produtos químicos. Oxidante para procedimentos capilares; selecionar a volumagem adequada e seguir instruções do fabricante. Observar validade.'],
            ['Tonalizante para cabelo', 'g', 'Perecível / produtos químicos. Tonalização dos cabelos; selecionar a tonalidade e seguir testes, aplicação e precauções do fabricante. Observar validade.'],
            ['Tonalizante para barba', 'g', 'Perecível / produtos químicos. Tonalização com produto indicado para barba; seguir testes, aplicação e precauções do fabricante. Observar validade.'],
            ['Produto para alisamento', 'ml', 'Perecível / produtos químicos. Alisamento capilar com produto autorizado para essa finalidade; seguir testes, aplicação e precauções do fabricante. Observar validade.'],
            ['Pedra hume em gel', 'ml', 'Perecível / assepsia e estancamento. Para pequenos cortes conforme indicação do fabricante; evitar contaminação do produto e observar validade.'],
            ['Pedra hume em pó', 'g', 'Perecível / assepsia e estancamento. Para pequenos cortes conforme indicação do fabricante; evitar contaminação do produto e observar validade.'],
            ['Álcool 70%', 'ml', 'Perecível / limpeza e assepsia. Higienização conforme indicação, tempo de contato e compatibilidade do fabricante; observar validade e cuidados de armazenamento.'],
            ['Clorexidina', 'ml', 'Perecível / assepsia. Usar somente na concentração e finalidade indicadas pelo fabricante; observar contraindicações, validade e prazo após abertura.'],
            ['Loção antisséptica para máquinas', 'ml', 'Perecível / limpeza e assepsia. Higienização das lâminas das máquinas com produto compatível; seguir tempo de contato e instruções do fabricante. Observar validade.'],
            ['Loção antisséptica para mãos', 'ml', 'Perecível / assepsia. Higienização das mãos com produto indicado para a pele; seguir instruções do fabricante e observar validade.'],
        ];
    }
}
