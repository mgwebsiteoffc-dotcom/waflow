<?php
namespace App\Services;
use App\Models\Shop;
use Illuminate\Support\Facades\Http;
use RuntimeException;
class WhatifyClient {
 public function __construct(private Shop $shop) {}
 private function call(string $method,string $path,array $payload=[]): array {
  if(!$this->shop->whatify_api_key) throw new RuntimeException('Connect a Whatify API key in Integrations first.');
  $request=Http::acceptJson()->withHeaders(['X-API-Key'=>$this->shop->whatify_api_key])->timeout(20);
  $url=rtrim(config('services.whatify.base_url'),'/').$path;
  $response=$method==='GET'?$request->get($url,$payload):$request->post($url,$payload);
  $data=$response->json()??[];
  if(!$response->successful()||($data['success']??true)===false) throw new RuntimeException($data['error_message']??$data['message']??$data['error']??'Whatify API request failed ('.$response->status().')');
  return $data;
 }
 public function accounts():array { $d=$this->call('GET','/whatsapp-accounts');return $d['whatsapp_accounts']??$d['data']??[]; }
 public function templates(?int $accountId=null):array { $d=$this->call('GET','/templates',array_filter(['whatsapp_account_id'=>$accountId]));return $d['templates']??$d['data']??[]; }
 public function sendTemplate(array $payload):array { return $this->call('POST','/send-template',$payload); }
}
