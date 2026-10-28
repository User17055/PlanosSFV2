<?php

namespace LuzPropria\LuzPanel\Controller\Front;

use LuzPropria\LuzPanel\Controller\ContainerAware;
use Symfony\Component\HttpFoundation\Request;
use Cocur\Slugify\Slugify;
use Symfony\Component\HttpFoundation\JsonResponse;


class HomeController extends ContainerAware
{

    public function IndexAction()
    {
        $quem_somos = $this->get('db')->fetchAssoc("SELECT * from institutional_type where id = 1");

        return $this->render('/front/index.twig', array(
            'quem_somos'=>$quem_somos,

        ));
    }

    public function AdocoesAction()
    {
        $adocoes_info = $this->get('db')->fetchAssoc("SELECT * from institutional_type where id = 2");
        $adocoes_info["body"] = str_replace("<p>", "<p data-aos=\"fade-up\" data-aos-delay=\"800\" data-aos-duration=\"800\">", $adocoes_info["body"]);

        $pets = $this->get('db')->fetchAll("SELECT * FROM adocoes_type where enabled = 1");

        return $this->render('/front/adocoes.twig', array(
            'adocoes_info'=>$adocoes_info,
            'pets'=>$pets,

        ));
    }

    public function ClinicaAction()
    {
        $clinica_info = $this->get('db')->fetchAssoc("SELECT * from institutional_type where id = 3");
        $clinica_info["body"] = str_replace("<p>", "<p data-aos=\"fade-up\" data-aos-delay=\"800\" data-aos-duration=\"800\">", $clinica_info["body"]);

        $unidades = $this->get('db')->fetchAll("SELECT * FROM unidades_type where enabled = 1");

        return $this->render('/front/clinica.twig', array(
            'clniica_info'=>$clinica_info,
            'unidades'=>$unidades,

        ));
    }


    public function BanhoTosaAction()
    {
        //$clinica_info = $this->get('db')->fetchAssoc("SELECT * from institutional_type where id = 3");
        //$clinica_info["body"] = str_replace("<p>", "<p data-aos=\"fade-up\" data-aos-delay=\"800\" data-aos-duration=\"800\">", $clinica_info["body"]);

        $banho_tosa = $this->get('db')->fetchAssoc("SELECT * from institutional_type where id = 5");

        return $this->render('/front/banho_tosa.twig', array(
            //'clniica_info'=>$clinica_info,
            'banho_tosa'=>$banho_tosa,

        ));
    }

    public function ServicosAction()
    {
        $servicos = $this->get('db')->fetchAll("SELECT * FROM servicos_type where enabled = 1");

        return $this->render('/front/hospital.twig', array(
            // 'clniica_info'=>$clinica_info,
            'servicos'=>$servicos,

        ));
    }

    public function PlanosSaudeAction(\LuzPropria\LuzPanel\Portal\PortalApi $portal)
    {
        $localPreview=getenv('APP_ENV')==='local' && getenv('PORTAL_LOCAL_PREVIEW')==='1';
        if ($localPreview) {
            // The optional local preview uses the API catalog without a copy of the production CMS database.
            $clinica_info=['title'=>'Mais cuidado para quem você ama.','body'=>'<p>Conheça nossos planos e acompanhe cada etapa do cuidado do seu pet.</p>'];
            $planos=[];
        } else {
        $clinica_info = $this->get('db')->fetchAssoc("SELECT * from institutional_type where id = 4");
        $clinica_info["body"] = str_replace("<p>", "<p data-aos=\"fade-up\" data-aos-delay=\"800\" data-aos-duration=\"800\">", $clinica_info["body"]);

        $planos = $this->get('db')->fetchAll("SELECT * FROM planos_type where enabled = 1");

        foreach ($planos as $key => $item) {
            $planos[$key]['subscription_url'] = null;
            $planos[$key]["beneficios"] = $this->get('db')->fetchAll("SELECT bt.* from
                                                                        planos_beneficios  pb
                                                                        inner join beneficios_type bt on bt.id = pb.id_beneficio
                                                                        where pb.id_plano = ? order by pb.id asc",array($item["id"]));
        }
        }

        $catalog = $portal->request('GET', 'catalog');
        $online = $catalog->ok() && !empty($catalog->data['offers']);
        if ($online) {
            $escape = static fn($text) => htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $planos = [];
            foreach ($catalog->data['offers'] as $offer) {
                $benefits = [];
                foreach ($offer['benefits'] as $benefit) {
                    $details = [];
                    $period=['contract_year'=>'por ano contratual','calendar_month'=>'por mês-calendário','monthly_cycle'=>'por ciclo mensal','lifetime'=>'durante a vigência'][$benefit['period_type']]??'conforme contrato';
                    $shortPeriod=['contract_year'=>'por ano','calendar_month'=>'por mês','monthly_cycle'=>'por ciclo mensal','lifetime'=>'na vigência'][$benefit['period_type']]??'';
                    $discount=($benefit['coverage_type']??'')==='discount';
                    $tags=[];
                    if ($discount) $tags[]=empty($benefit['discount_basis_points'])?'Desconto':number_format($benefit['discount_basis_points']/100,0,',','.').'% de desconto';
                    if ($benefit['quantity_limit'] !== null) {
                        $details[] = 'Quantidade: '.$benefit['quantity_limit'].' '.$period.'.';
                        $tags[] = trim($benefit['quantity_limit'].' '.$shortPeriod);
                    }
                    if ($discount && !empty($benefit['discount_basis_points'])) $details[]='Desconto: '.number_format($benefit['discount_basis_points']/100,0,',','.').'%, conforme contrato.';
                    if ($benefit['waiting_days']) $details[] = 'Carência: '.$benefit['waiting_days'].' dias.';
                    if ($benefit['description']) $details[] = $benefit['description'];
                    // Structured fields feed the compact cards and the comparison table; body stays for the CMS-shaped fallback.
                    $benefits[] = ['title'=>$escape($benefit['name']), 'body'=>$escape(implode(' ', $details)),
                        'included'=>!$discount, 'tag'=>$tags?implode(' · ',$tags):'Incluso',
                        'waiting_days'=>(int)$benefit['waiting_days'], 'note'=>(string)($benefit['description']??'')];
                }
                usort($benefits, static fn($a, $b) => $b['included'] <=> $a['included']);
                $planos[] = ['title'=>$offer['name'], 'beneficios'=>$benefits, 'offer'=>$offer,
                    'subscription_url'=>$this->get('url_generator')->generate('portal_subscribe', ['code'=>$offer['code']])];
            }
        }

        // Line every coverage up across plans (most shared first) and highlight the broadest plan.
        $planos = array_values($planos);
        $comparativo = [];
        $destaque = null;
        if (count($planos) > 1) {
            foreach ($planos as $index => $item) {
                foreach ($item['beneficios'] as $bene) {
                    $name = trim(html_entity_decode(strip_tags((string)$bene['title']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    $key = mb_strtolower($name);
                    $comparativo[$key] ??= ['name'=>$name, 'cells'=>array_fill(0, count($planos), null), 'order'=>count($comparativo)];
                    $comparativo[$key]['cells'][$index] = $bene;
                }
                $score = [count($item['beneficios']), $item['offer']['price_cents'] ?? 0];
                if ($destaque === null || $score > $destaqueScore) { $destaque = $index; $destaqueScore = $score; }
            }
            uasort($comparativo, static fn($a, $b) => [count(array_filter($b['cells'])), $a['order']] <=> [count(array_filter($a['cells'])), $b['order']]);
        }

        return $this->render('/front/planos.twig', array(
            'clinica_info'=>$clinica_info,
            'planos'=>$planos,
            'comparativo'=>array_values($comparativo),
            'plano_destaque'=>$destaque,
            'subscription_portal'=>$this->get('url_generator')->generate('portal_home'),
            'online_available'=>$online,

        ));
    }

    public function FranqueadoAction()
    {
        return $this->render('/front/franqueado.twig', array());
    }

    public function WhatsappAction()
    {
        return new \Symfony\Component\HttpFoundation\RedirectResponse('https://wa.me/5518996642021?text=Oi%2C%20recebi%20um%20SMS%20de%20voc%C3%AAs%20e%20gostaria%20de%20mais%20informa%C3%A7%C3%B5es%21');
    }

    public function SendContatoAction(Request $request)
    {
        // O envio atual continua na API externa configurada nos formul?rios.
        // A antiga rota sem implementa??o passa a responder de forma expl?cita.
        return new JsonResponse(['ok' => false, 'message' => 'Utilize o formul?rio de contato do site ou nosso WhatsApp.'], 410);
    }
}
