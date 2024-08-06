<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Exception;

class RutController extends Controller
{
    // Define las constantes usadas en el código original
    const XPATH_RAZON_SOCIAL = '/html/body/div/div[4]';
    const XPATH_INICIO_ACTIVIDADES = '/html/body/div/div[7]';
    const XPATH_ACTIVIDADES = '/html/body/div/table[1]/tr';
    const URL_CAPTCHA = 'https://zeus.sii.cl/cvc_cgi/stc/CViewCaptcha.cgi';
    const URL_CONSULTA = 'https://zeus.sii.cl/cvc_cgi/stc/getstc';

    public function getChileRutData(Request $request, $rut)
    {
        try {
            $data = $this->fetchRutData($rut);
            return response()->json($data);
        } catch (Exception $e) {
            Log::error('Error al obtener datos de RUT', ['rut' => $rut, 'error' => $e->getMessage()]);
            return response()->json([
                'name' => '',
                'activities' => []
            ], 500);
        }
    }

    private function fetchRutData($rut)
    {
        $formattedRut = $this->formatClRut($rut);

        if (strlen($formattedRut) !== 10) {
            throw new Exception('RUT no válido');
        }

        $client = new Client();
        $captcha = $this->fetchCaptcha($client);
        $rutParts = explode('-', $formattedRut);
        $rut = $rutParts[0];
        $dv = $rutParts[1];

        $data = [
            'RUT' => $rut,
            'DV' => $dv,
            'PRG' => 'STC',
            'OPC' => 'NOR',
            'txt_code' => $captcha['code'],
            'txt_captcha' => $captcha['captcha']
        ];

        $response = $client->post(self::URL_CONSULTA, ['form_params' => $data]);
        $html = (string) $response->getBody();
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        $razonSocial = $this->extractText($xpath, self::XPATH_RAZON_SOCIAL);
        $actividades = $this->extractActivities($xpath);

        return [
            'rut' => $rut,
            'name' => $razonSocial,
            'activities' => $actividades
        ];
    }

    private function fetchCaptcha($client)
    {
        try {
            $response = $client->post(self::URL_CAPTCHA, ['form_params' => ['oper' => 0]]);
            $data = json_decode($response->getBody(), true);

            $decodedCaptcha = base64_decode($data['txtCaptcha']);
            if ($decodedCaptcha === false) {
                throw new Exception('Failed to decode captcha');
            }

            if (strlen($decodedCaptcha) < 40) {
                throw new Exception('Decoded captcha is too short');
            }

            return [
                'code' => substr($decodedCaptcha, 36, 4),
                'captcha' => $data['txtCaptcha']
            ];
        } catch (Exception $e) {
            throw new Exception('Error fetching captcha: ' . $e->getMessage());
        }
    }

    private function extractText($xpath, $query)
    {
        $nodeList = $xpath->query($query);
        if ($nodeList->length > 0) {
            return trim($nodeList->item(0)->textContent);
        }
        return '';
    }

    private function extractActivities($xpath)
    {
        $activities = [];
        $rows = $xpath->query(self::XPATH_ACTIVIDADES);
        $skipFirstZeroCode = true;

        foreach ($rows as $row) {
            $columns = $xpath->query('./td/font', $row);
            if ($columns->length === 5) {
                $codigo = (int)trim($columns->item(1)->textContent);

                Log::info('Row content: ' . json_encode([
                    'actividades' => trim($columns->item(0)->textContent),
                    'codigo' => $codigo,
                    'categoria' => trim($columns->item(2)->textContent),
                    'afecta' => trim($columns->item(3)->textContent),
                    'fecha' => trim($columns->item(4)->textContent),
                ]));

                if ($skipFirstZeroCode && $codigo === 0) {
                    $skipFirstZeroCode = false;
                    continue;
                }

                $activities[] = [
                    'activities' => trim($columns->item(0)->textContent),
                    'codigo' => $codigo,
                    'categoria' => trim($columns->item(2)->textContent),
                    'afecta' => trim($columns->item(3)->textContent) === 'Si',
                    'fecha' => trim($columns->item(4)->textContent),
                ];
            }
        }

        return $activities;
    }

    public static function formatClRut($rut)
    {
        if (strlen($rut) < 3) {
            return null;
        }

        $rut = preg_replace('/[^0-9Kk]/', '', $rut);

        return substr($rut, 0, -1).'-'.substr($rut, -1, 1);
    }
}
