<?php
namespace App\Console\Commands;

use App\Jobs\PlayerBetFlowHandle;
use App\Jobs\PlayerBetFlowSynchronizeFailHandle;
use App\Jobs\PlayerRebateFinancialFlowHandle;
use App\Jobs\SendReminderEmail;
use App\Models\System\ReminderEmail;
use App\Models\CarrierPayChannel;
use App\Models\Log\PlayerLoginLog;
use App\Models\Player;
use App\Models\PlayerGameAccount;
use App\Models\Log\PlayerBetFlowLog;
use App\Vendor\GameGateway\Gateway\Exception\GameGateWaySynchronizeDBException;
use App\Vendor\GameGateway\Gateway\GameGatewayRunTime;
use App\Vendor\Pay\Gateway\PayOrderRuntime;
use App\Vendor\GameGateway\Bbin\BBin;
use App\Vendor\GameGateway\OneWorks\OneWorks;
use Carbon\Carbon;
use Illuminate\Console\Command;
use App\Http\Controllers\AppBaseController;

class SynchronizeBBinLiveGameFlowCommand extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'synchronizeGameData:bbinlive';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * BBin真人
     *
     * @return mixed
     */
    public function handle()
    {
        $time = time() - 43200;
        $param = array(
            'keyb' => BBin::BetRecordKeyB,
            'uppername' => BBin::Uppername,
            'website' => BBin::Website,
            'front' => '1',
            'after' => '7',
            'page' => '1',
            'gamekind' => 3
        );
        $param = $this->timeInterval($param, $time, 5);
        $this->bbinMorePage($param, 'bbinlive');
    }

    // 多页抓取
    private function bbinMorePage($param, $fun)
    {
        $bin = new BBin();
        do {
            $result = '';
            do {
                $result = $bin->remoteApi(BBin::API_BetRecord, $param, 0, 0);
            } while (! $result['result']);
            if ($result['pagination']['Page'] != $result['pagination']['TotalPage']) {
                $param['page'] = $param['page'] + 1;
            }
            
            // 写入数据
            $this->$fun($result);
        } while ($result['pagination']['Page'] < $result['pagination']['TotalPage']);
    }

    // @interval 分钟数抓取多长的订单
    // 抓取间隔规则
    private function timeInterval($param, $time, $interval)
    {
        $minutetime = $interval * 60;
        $dawn = strtotime(date('Y-m-d', $time));
        if ($time - $dawn < $minutetime / 2) {
            // 如果在0：05之前只查前一天数据
            $param['rounddate'] = date("Y/m/d", $time - $minutetime);
            $param['starttime'] = date("H:i:s", $dawn - $minutetime / 2);
            $param['endtime'] = date("H:i:s", $dawn - 1);
        } else if ($time - $dawn >= $minutetime / 2 && $time - $dawn < $minutetime) {
            // 如果在0：05至0:10时查当天所有数据
            $param['rounddate'] = date("Y/m/d", $time);
        } else {
            // 如果在0:10分之后查前10分钟数据
            $param['rounddate'] = date("Y/m/d", $time);
            $param['starttime'] = date("H:i:s", $time - $minutetime);
            $param['endtime'] = date("H:i:s", $time);
        }
        return $param;
    }

    // BB视讯处理
    public function bbinlive($result)
    {
        \WLog::info("======> bbin live 获取数据成功", $result);
        if ($result['result']) {
            foreach ($result['data'] as $key => $vaule) {
                $isexit = PlayerBetFlowLog::where('game_flow_code', $vaule['WagersID'])->where('game_plat_id', 2)->first();
                if (is_null($isexit)) {
                    $playerBetFlowLog = new PlayerBetFlowLog();
                    $playerGameAccount = PlayerGameAccount::where('account_user_name', $vaule['UserName'])->first();
                    $playerBetFlowLog->player_id = $playerGameAccount->player_id;
                    $player = Player::where('player_id', $playerGameAccount->player_id)->first();
                    $playerBetFlowLog->carrier_id = $player->carrier_id;
                    
                    $playerBetFlowLog->game_type = $vaule['GameType'];
                    $playerBetFlowLog->game_id = 0;
                    $playerBetFlowLog->game_plat_id = 2;
                    $playerBetFlowLog->game_flow_code = $vaule['WagersID'];
                    $playerBetFlowLog->player_or_banker = 0;
                    $BetAmount = sprintf("%.2f", abs($vaule['BetAmount']));
                    $payOff = sprintf("%.2f", $vaule['Payoff']);
                    if (bccomp($payOff, 0.00, 2) == 0) {
                        $payOff = $BetAmount;
                    } elseif (bccomp($payOff, 0.00, 2) < 0) {
                        $payOff = 0.00;
                    }
                    
                    $playerBetFlowLog->bet_amount = $BetAmount;
                    $playerBetFlowLog->available_bet_amount = sprintf("%.2f", abs($vaule['Commissionable']));
                    $playerBetFlowLog->company_payout_amount = $payOff;
                    $playerBetFlowLog->bet_flow_available = $vaule['Commissionable'] ? 1 : 0;
                    $playerBetFlowLog->bet_info = $vaule['WagerDetail'];
                    
                    if ($vaule['ResultType'] == ' ' || empty(trim($vaule['ResultType']))) {
                        $playerBetFlowLog->game_status = 1;
                        $playerBetFlowLog->company_win_amount = bcsub($playerBetFlowLog->bet_amount, $playerBetFlowLog->company_payout_amount, 2);
                    } else if ($vaule['ResultType'] == '-1') {
                        $playerBetFlowLog->game_status = 2;
                        $playerBetFlowLog->company_win_amount = 0;
                    } else {
                        $playerBetFlowLog->game_status = 0;
                        $playerBetFlowLog->company_win_amount = 0;
                    }
                    try {
                        $playerBetFlowLog->save();
                    } catch (\PDOException $e) {
                        \WLog::error("======> bbin live insert 同步数据异常 " . $e->getMessage());
                    }
                } else {
                    $isexit->available_bet_amount = sprintf("%.2f", abs($vaule['Commissionable']));
                    $isexit->company_payout_amount = sprintf("%.2f", abs($vaule['Payoff']));
                    $isexit->bet_flow_available = $vaule['Commissionable'] ? 1 : 0;
                    $isexit->bet_info = '';
                    if ($vaule['ResultType'] == ' ') {
                        $isexit->game_status = 1;
                        $isexit->company_win_amount = bcsub($playerBetFlowLog->bet_amount, $playerBetFlowLog->company_payout_amount, 2);
                    } else if ($vaule['ResultType'] == '-1') {
                        $isexit->game_status = 2;
                        $isexit->company_win_amount = 0;
                    } else {
                        $isexit->game_status = 0;
                        $isexit->company_win_amount = 0;
                    }
                    try {
                        $isexit->save();
                    } catch (\PDOException $e) {
                        \WLog::error("======> bbin live insert 同步数据异常 " . $e->getMessage());
                    }
                }
            }
        }
    }
}
