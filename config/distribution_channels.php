<?php

function distribution_channels(): array {
  return [
    'nexus_b2b' => ['name' => 'NEXUS B2B acente ağı', 'description' => 'NEXUS tedarikçi ağına bağlı yetkili acenteler.'],
    'rezervasyonyap' => ['name' => 'RezervasyonYap.tr', 'description' => 'NEXUS’un son kullanıcı satış kanalı.'],
    'nexus_api' => ['name' => 'NEXUS API / XML partnerleri', 'description' => 'Sözleşmeli yerli ve yabancı acente bağlantıları.'],
    'direct_widget' => ['name' => 'Kendi web siteniz / rezervasyon widgetı', 'description' => 'Tedarikçinin kendi web sitesine bağlanacak satış bileşeni.'],
    'partner_ota' => ['name' => 'Anlaşmalı OTA / pazar yeri', 'description' => 'Yalnızca aktif sözleşme ve teknik entegrasyon varsa yayına alınır.'],
  ];
}
