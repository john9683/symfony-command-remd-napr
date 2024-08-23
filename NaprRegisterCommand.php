<?php

declare(strict_types=1);

namespace App\Command;

use App\Util\IDB;
use App\Service\Egisz\Remd\DataProvider;
use App\Util\DateTimeImmutable;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Style\SymfonyStyle;

class NaprRegisterCommand extends Command
{
  /** @var IDB */
  protected $db;

  /** @var DataProvider */
  protected $data;

  public function __construct(IDB $db, DataProvider $data)
  {
    parent::__construct();
    $this->db = $db;
    $this->data   = $data;
  }

  /**
   * @var string $defaultName
   */
  protected static $defaultName = 'app:remd:napr';

  /**
   * @var string $defaultDescription
   * вызов: /www/php7/bin/php /www/htdocs/seven/bin/console app:remd:napr --month=03 --day=12
   */
  protected static $defaultDescription = 'Первичная отправка в РЭМД направлений на обследование и консультацию';

  /**
   * @return void
   */
  protected function configure(): void
  {
    $this->setDescription(self::$defaultDescription);
    $this->addOption('month', 'm', InputOption::VALUE_OPTIONAL, 'Месяц начала периода');
    $this->addOption('day', 'd', InputOption::VALUE_OPTIONAL, 'День начала периода');
  }

  public const SQL_ID_RES = <<<SQL
SELECT r.ID_USER_SEND, r.ID_RES, me.DS
FROM RESULTS r
        JOIN DEP_HSP dh ON r.ID_HSP = dh.ID_HSP
        JOIN MKB m ON m.MKB = dh.MKB
            AND dh.ID_DEPHSP=(SELECT MAX(ID_DEPHSP) FROM DEP_HSP WHERE ID_HSP =  r.ID_HSP) AND dh.MKB IS NOT NULL
        JOIN MEASUR me ON me.ID_HSP = dh.ID_HSP
            AND me.DS IS NOT NULL
        JOIN ANALYSIS a ON r.ID_ANAL = a.ID_ANAL
        JOIN USER_SIGN us ON r.ID_USER_SEND = us.ID_USER AND us.FINGERPRINT IS NOT NULL
        JOIN USERS u ON r.ID_USER_SEND = u.ID_USER
    AND u.D_BIR IS NOT NULL
    AND u.SNILS IS NOT NULL
    AND u.PRVS IS NOT NULL
    AND u.PRVS_V015 IS NOT NULL
    AND u.ID_NSIPOST IS NOT NULL
    AND u.OLD_MARK IS NULL
WHERE r.DATE_IN BETWEEN ? AND ?
ORDER BY r.ID_USER_SEND
SQL;

  /**
   * @param string $from
   * @param string $to
   * @return array
   */
  private function getIdResArray(string $from, string $to): array
  {
    $resData = $this->db->rows(self::SQL_ID_RES, [strtotime($from), strtotime($to)]);
    $userArray = [];

    foreach ($resData as $user) {
      if (!in_array($user['ID_USER_SEND'], $userArray)) {
        $userArray = $userArray + [ $user['ID_USER_SEND'] => $user['ID_RES'] ];
      }
    }

    return $userArray;
  }

  /**
   * @param InputInterface $input
   * @param OutputInterface $output
   * @return void
   */
  protected function execute(InputInterface $input, OutputInterface $output): void
  {
    $fromDefault = (new DateTimeImmutable())::createFromTimestamp(strtotime(date('Y-m-d')))
      ->format('Y-m-d 00:00:00');
    $toDefault = (new DateTimeImmutable())::createFromTimestamp(strtotime(date('Y-m-d')))
      ->format('Y-m-d 24:00:00');

    $month = $input->getOption('month');
    $day = $input->getOption('day');
    $from = $day && $month ? (new DateTimeImmutable())::createFromTimestamp(strtotime(date('Y-m-d')))
      ->format('Y-' . $month . '-' . $day . ' 00:00:00') : $fromDefault;
    $to = $day && $month ? (new DateTimeImmutable())::createFromTimestamp(strtotime(date('Y-m-d')))
      ->format('Y-' . $month . '-' . $day . ' 24:00:00') : $toDefault;

    $userArray = $this->getIdResArray($from, $to);
    $console = new SymfonyStyle($input, $output);
    $progress = new ProgressBar($output);
    $progress->setMaxSteps(count($userArray));
    $rows = [];
    $count = 0;
    $title = 'Уникальных врачей с заполненным профилем, выполнивших направление на обследование'
      . ' ' . substr($from,0, 10) . ': ';

    foreach ($userArray as $user=>$value) {

      $command = 'sudo -u daemon /www/php7/bin/php /www/htdocs/seven/bin/console app:remd:reg ' . 'n-' . $value;

      system(
        $command,
        $code
      );

      if ($code === 0) {
        $result = 'register';
      } else {
        $result = 'error';
      }

      $rows[] = [
        ++$count,
        $user,
        'n-' . $value,
        $result
      ];

      $progress->advance();
    }

    $progress->finish();

    $table = new Table($output);
    $table
      ->setHeaders(['#', 'ID_USER', 'NUMBER', 'RESULT'])
      ->setRows($rows)
    ;

    if (count($userArray) > 0) {
      $console->title(PHP_EOL . '     Отправка СЭМД завершена    ');
      $table->render();
      $console->success($title . count($userArray));
    } else {
      $console->warning(
        "Направлений на обследование не найдено");
    }
  }
}
