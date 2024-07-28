<?php

namespace App\Controllers;

use App\Models\GenSetsModel;
use App\Models\RunsModel;

class Runs extends BaseController
{

    public function index()
    {
        $user = auth()->user();
        if (!$user)
            return redirect()->to('login');

        $model = model(RunsModel::class);

        $runs = $model->getRuns();
        $runs = $model->parseRuns($runs);
        $data = [
            'runs' => $runs,
            'title' => 'Запуски генераторів',
        ];
        return view('templates/header', $data)
            . view('runs/index', $data)
            . view('templates/footer');
    }

    public function start()
    {
        $user = auth()->user();
        if (!$user)
            return redirect()->to('login');

        // todo - додати меседж
        $validationRules = [
            'runGenId'   => 'required|integer',
            'runStartDate'   => 'required|min_length[9]|integer',
            'runType' => 'required|in_list[Аварія,Тест]',
            'runResult' => 'required|in_list[Запустився,Не запустився]',
        ];
        if ($this->validate($validationRules)) {
            // save runs to Runs table
            if ($this->request->getPost('runResult') == 'Не запустився') {
                $data = [
                    'stopDate' => $this->request->getPost('runStartDate'),
                    'genId'   => $this->request->getPost('runGenId'),
                    'startDate'   => $this->request->getPost('runStartDate'),
                    'runType' => $this->request->getPost('runType'),
                    'runResult' => $this->request->getPost('runResult'),
                    ];
                $genState = 2;
            }
            else {
                $data = [
                    'genId'   => $this->request->getPost('runGenId'),
                    'startDate'   => $this->request->getPost('runStartDate'),
                    'runType' => $this->request->getPost('runType'),
                    'runResult' => $this->request->getPost('runResult'),
                ];
                $genState = 1;
            }

            $model = model(RunsModel::class);
            $model->save($data);
            // update state after runs to Gen table
            $genModel = model(GenSetsModel::class);
            $genData = [
                'genId'   => $this->request->getPost('runGenId'),
                'genState' => $genState, // working or no
            ];
            $genModel->save($genData);
            return $this->response->setJSON(['success' => 'Генератор успішно запустився']);
        } else {
            return $this->response->setJSON(['success' => false, 'errors' => $this->validator->getErrors()]);
        }
    }

    public function stop()
    {
        $user = auth()->user();
        if (!$user)
            return redirect()->to('login');

        // todo - додати меседж
        $validationRules = [
            'stopGenId'   => 'required|integer',
            'runStopDate'   => 'required|min_length[9]|integer',
        ];
        if ($this->validate($validationRules)) {
            $gen_id = $this->request->getPost('stopGenId');
            $stop_date = $this->request->getPost('runStopDate');
            $db = \Config\Database::connect();
            $query = $db->query('SELECT startDate FROM `generator`.`genset__runs` WHERE  genId = ? and stopDate IS NULL;', [$gen_id]);
            $row   = $query->getRow();
            $start_date = $row->startDate;
            // save runs to Runs table
            $model = model(RunsModel::class);

            $model
                ->where('stopDate', null)
                ->where('genId', $this->request->getPost('stopGenId'))
                ->set('stopDate', $this->request->getPost('runStopDate'))
                ->update();
            // update state after runs to Gen table
            $query = $db->query('SELECT litresPerHour FROM genset__types WHERE id = (SELECT genTypeId FROM gensets WHERE genId = ?);', [$gen_id]);
            $row   = $query->getRow();
            $base_fuel_usage = $row->litresPerHour;
            $query = $db->query('SELECT genLitres FROM gensets WHERE genId = ?;', [$gen_id]);
            $row   = $query->getRow();
            $fuel_start = $row->genLitres;
            $work_time = $stop_date - $start_date;
            $fuel_used = $base_fuel_usage * $work_time / 3600;
            $fuel_left = round($fuel_start, 2) - round($fuel_used, 2);
            $genModel = model(GenSetsModel::class);
            $genData = [
                'genId'   => $this->request->getPost('stopGenId'),
                'genState' => null, // working or no
                'genLitres' => $fuel_left
            ];
            $genModel->save($genData);
            return $this->response->setJSON(['success' => 'Генератор успішно зупинено', 'work_time' => $work_time, 'fuel_used' => round($fuel_used, 2), 'fuel_left' => $fuel_left]);
        } else {
            return $this->response->setJSON(['success' => false, 'errors' => $this->validator->getErrors()]);
        }
    }


}
