<?php
require_once __DIR__ . '/vendor/autoload.php';

use Carbon\Carbon;
use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;

class KVV_Verkehrsmeldungen {
    private static Client  $client;
    private static Crawler $crawler;

    public static function fetch(): void {
        print "Fetching KVV Verkehrsmeldungen..." . PHP_EOL;

        self::$client  = new Client();
        self::$crawler = self::$client->request('GET', 'https://www.kvv.de/fahrplan/verkehrsmeldungen.html');

        self::$crawler->filter('.ix_kvv_ticker_list[ontop="1"]')
                      ->each(function(Crawler $node) {
                          print "----------------------------------------" . PHP_EOL;
                          print "Parsing node..." . PHP_EOL;

                          $detailLink = $node->filter('a.internal-link')->attr('href');
                          $nettroId   = explode('Nettro_CMS_', $detailLink)[1];
                          print "Nettro ID: $nettroId" . PHP_EOL;

                          $validFrom = Carbon::createFromTimestamp($node->attr('validfrom'));
                          $validTo   = Carbon::createFromTimestamp($node->attr('validto'));

                          $subject = $node->filter('.ticker_subject')->text();
                          $text    = $node->filter('p')->text();


                          $status = self::checkStatus($nettroId, $subject, $text);

                          print "Subject: $subject" . PHP_EOL;
                          print "Status: " . $status->value . PHP_EOL;

                          if($status === Status::UNCHANGED) {
                              print "Skipping..." . PHP_EOL;
                              return;
                          }

                          $message = "<i>[$nettroId]</i> <b>$subject</b>" . PHP_EOL;
                          $message .= "<i>" . $status->value . "e Meldung</i> - ";
                          $message .= "gültig von " . $validFrom->format('d.m.Y H:i') . " bis " . $validTo->format('d.m.Y H:i') . PHP_EOL;
                          $message .= PHP_EOL;
                          $message .= "<i>-------------- Kurzfassung --------------</i>" . PHP_EOL;
                          $message .= $text . PHP_EOL;
                          $message .= PHP_EOL;
                          $message .= "<i>-------------- Details --------------</i>" . PHP_EOL;
                          $message .= self::getFullTextFromDetailPage($detailLink);

                          self::sendTelegramMessage(
                              chat:    '@kvv_verkehrsmeldungen',
                              message: $message,
                          );
                      });
    }

    private static function getFullTextFromDetailPage($href): string {
        $crawler = self::$client->request('GET', $href);
        $message = $crawler->filter('.ix_kvv_ticker p')->html();
        $message = str_replace(['<br>', '<br />', '<br/>'], PHP_EOL, $message);
        return strip_tags($message);
    }

    private static function checkStatus($nettroId, $subject, $summary): Status {
        //Save the variables to local csv
        $file          = fopen('sent.csv', 'ab+');
        $foundNettroId = false;
        while($row = fgetcsv($file)) {
            if($row[1] === $nettroId) {
                $foundNettroId = true;
                if($row[2] === $subject && $row[3] === $summary) {
                    return Status::UNCHANGED;
                }
            }
        }

        self::saveToCSV($nettroId, $subject, $summary);
        if($foundNettroId) {
            return Status::UPDATED;
        }

        fclose($file);
        return Status::NEW;
    }

    private static function saveToCSV($nettroId, $subject, $text): void {
        $file = fopen('sent.csv', 'ab+');
        fputcsv($file, [time(), $nettroId, $subject, $text]);
        fclose($file);
    }

    private static function sendTelegramMessage($chat, $message): void {
        print "Sending Telegram message..." . PHP_EOL;
        $client = new \GuzzleHttp\Client();
        $client->post('https://api.telegram.org/bot' . getenv('TELEGRAM_API_TOKEN') . '/sendMessage', [
            'json' => [
                'chat_id'    => $chat,
                'text'       => $message,
                'parse_mode' => 'HTML',
            ],
        ]);
    }
}

enum Status: string {
    case NEW       = 'Neu';
    case UPDATED   = 'Aktualisiert';
    case UNCHANGED = 'Unverändert';
}

KVV_Verkehrsmeldungen::fetch();
