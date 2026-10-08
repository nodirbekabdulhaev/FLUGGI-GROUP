<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Lesson;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Room;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\AttendanceService;
use App\Services\EnrollmentService;
use App\Services\LeadService;
use App\Services\OrganizationSetup;
use App\Services\PaymentService;
use App\Services\SalaryService;
use App\Services\ScheduleService;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Demo data (spec §65): 1 organization, 2 branches, 5+ users, 10 teachers, 100 students,
 * 10 groups, 10 courses, 100+ payments, lessons, attendance, leads.
 * Run: php artisan db:seed --class=DemoSeeder      (deterministic, safe to re-run on an empty DB)
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Demo2026pass';

    public function run(): void
    {
        mt_srand(2026);
        AuditLog::$enabled = false;
        OrganizationSetup::syncRoles();

        $setup = app(OrganizationSetup::class);
        $org = $setup->createOrganization("Rizo Ta'lim (DEMO)", ['webhook_key' => 'demo-webhook-key-0123456789abcdef']);

        Tenant::run($org, function () use ($org) {
            $this->seedAll($org);
        });

        AuditLog::$enabled = true;
        $this->command?->table(['Роль', 'Логин', 'Пароль'], [
            ['Super Admin', 'admin@demo.uz', self::PASSWORD], ['Директор', 'director@demo.uz', self::PASSWORD],
            ['Администратор (Sardoba)', 'administrator@demo.uz', self::PASSWORD], ['Sales Manager', 'sales@demo.uz', self::PASSWORD],
            ['Бухгалтер', 'accountant@demo.uz', self::PASSWORD], ['Преподаватель', 'teacher1@demo.uz', self::PASSWORD],
        ]);
    }

    private function pick(array $a): mixed
    {
        return $a[mt_rand(0, count($a) - 1)];
    }

    private function seedAll($org): void
    {
        // ---- branches / rooms
        $branches = collect(['Sardoba', 'Nurtepa'])->map(fn ($n, $i) => Branch::create(['name' => $n, 'address' => 'Ташкент, '.$n, 'phone' => '+99871200000'.$i, 'manager_name' => $i ? 'Aziza Karimova' : 'Bobur Aliyev']));
        $rooms = collect();
        foreach ($branches as $b) {
            foreach (['Room 101', 'Room 204', 'Room 305'] as $n) {
                $rooms->push(Room::create(['branch_id' => $b->id, 'name' => $n, 'floor' => substr($n, 5, 1), 'capacity' => 16, 'equipment' => 'проектор, доска']));
            }
        }

        // ---- users
        $roleId = fn ($slug) => Role::where('slug', $slug)->whereNull('organization_id')->value('id');
        $mk = function (string $name, string $email, string $phone, string $role, ?int $branch = null) use ($roleId) {
            $u = User::create(['name' => $name, 'email' => $email, 'phone' => $phone, 'password' => self::PASSWORD, 'branch_id' => $branch, 'is_active' => true, 'locale' => 'ru']);
            $u->roles()->attach($roleId($role));

            return $u;
        };
        $admin = $mk('Super Admin', 'admin@demo.uz', '+998901000001', 'super_admin');
        $mk('Sardor Director', 'director@demo.uz', '+998901000002', 'director');
        $adm = $mk('Aziz Administrator', 'administrator@demo.uz', '+998901000003', 'admin', $branches[0]->id);
        $sales = $mk('Madina Sales', 'sales@demo.uz', '+998901000004', 'sales_manager');
        $sales2 = $mk('Jasur Sales', 'sales2@demo.uz', '+998901000007', 'sales_manager');
        $mk('Nilufar Accountant', 'accountant@demo.uz', '+998901000005', 'accountant');
        $teacherUsers = [$mk('Teacher One', 'teacher1@demo.uz', '+998901000011', 'teacher'), $mk('Teacher Two', 'teacher2@demo.uz', '+998901000012', 'teacher')];

        // ---- subjects / courses
        $subjects = collect(['Английский язык', 'Математика', 'IT / Программирование', 'Русский язык', 'Подготовка к экзаменам'])->map(fn ($n) => Subject::create(['name' => $n]));
        $courseDefs = [
            ['IELTS', 0, 600000, 3, 36], ['General English', 0, 450000, 3, 36], ['Kids English', 0, 350000, 3, 24], ['Math Pro', 1, 400000, 3, 36], ['Python Start', 2, 700000, 4, 32],
            ['Web Development', 2, 900000, 6, 48], ['Русский для всех', 3, 300000, 2, 24], ['SAT Prep', 4, 800000, 3, 30], ['Speaking Club', 0, 250000, 1, 12], ['Олимпиадная математика', 1, 500000, 4, 40],
        ];
        $courses = collect($courseDefs)->map(function ($c) use ($subjects, $branches) {
            $course = Course::create(['subject_id' => $subjects[$c[1]]->id, 'name' => $c[0], 'description' => 'Демо-курс '.$c[0], 'duration_months' => $c[3], 'lessons_count' => $c[4], 'price' => $c[2], 'status' => 'active']);
            $course->branches()->sync($branches->pluck('id'));

            return $course;
        });

        // ---- teachers
        $tNames = ['Sardor Rahimov', 'Dilnoza Karimova', 'Bekzod Aliyev', 'Malika Usmonova', 'Otabek Yusupov', 'Zarina Nazarova', 'Jahongir Tursunov', 'Shahnoza Ergasheva', 'Rustam Saidov', 'Kamola Ismoilova'];
        $payTypes = [['fixed', 5000000], ['per_lesson', 100000], ['per_student', 50000], ['percent', 20]];
        $teachers = collect($tNames)->map(function ($n, $i) use ($branches, $subjects, $payTypes, $teacherUsers) {
            [$type, $rate] = $payTypes[$i % 4];

            return Teacher::create([
                'branch_id' => $branches[$i % 2]->id, 'user_id' => $teacherUsers[$i]->id ?? null, 'subject_id' => $subjects[$i % 5]->id, 'full_name' => $n,
                'phone' => '+99890'.mt_rand(1000000, 9999999), 'telegram' => '@'.strtolower(explode(' ', $n)[0]), 'pay_type' => $type, 'rate' => $rate,
                'hired_at' => now()->subMonths(mt_rand(2, 40))->toDateString(), 'status' => 'active',
            ]);
        });
        foreach ([['Gulnora Admin', 'Администратор'], ['Ilhom Cleaner', 'Хозяйственный отдел']] as $i => [$n, $pos]) {
            Employee::create(['branch_id' => $branches[$i]->id, 'full_name' => $n, 'position' => $pos, 'phone' => '+998935'.mt_rand(100000, 999999), 'pay_type' => 'fixed', 'rate' => 3000000, 'hired_at' => now()->subYear()->toDateString(), 'status' => 'active']);
        }

        // ---- groups + schedules
        $start = now()->subDays(45)->startOfDay();
        $groups = collect();
        $schedule = app(ScheduleService::class);
        foreach (range(0, 9) as $i) {
            $branch = $branches[$i % 2];
            $branchRooms = $rooms->where('branch_id', $branch->id)->values();
            $course = $courses[$i];
            $group = Group::create([
                'branch_id' => $branch->id, 'course_id' => $course->id, 'teacher_id' => $teachers[$i]->id, 'room_id' => $branchRooms[intdiv($i, 2) % 3]->id,
                'name' => strtoupper(substr(str_replace(' ', '', $course->name), 0, 4)).'-'.(10 + $i), 'max_students' => 15,
                'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addMonths($course->duration_months)->toDateString(),
                'status' => $i === 9 ? 'enrolling' : 'active',
            ]);
            $groups->push($group);
            $days = $i % 2 ? [2, 4, 6] : [1, 3, 5];
            $hour = 9 + intdiv($i, 2) * 2;       // distinct slots per room/branch pair
            foreach ($days as $d) {
                $schedule->create(['group_id' => $group->id, 'weekday' => $d, 'start_time' => sprintf('%02d:00', $hour), 'end_time' => sprintf('%02d:30', $hour + 1),
                    'start_date' => $start->toDateString(), 'end_date' => $group->end_date->toDateString(), 'is_active' => true]);
            }
        }

        // ---- guardians + students
        $first = ['Muhammad', 'Aziz', 'Madina', 'Bekzod', 'Ali', 'Sevara', 'Jasur', 'Dilshod', 'Nodira', 'Sherzod', 'Gulnoza', 'Timur', 'Laylo', 'Akmal', 'Zilola', 'Farhod', 'Shahzod', 'Malika', 'Umid', 'Nargiza'];
        $last = ['Abdullayev', 'Karimov', 'Rahimov', 'Yusupov', 'Saidov', 'Tursunov', 'Ergashev', 'Nazarov', 'Ismoilov', 'Usmonov'];
        $sources = LeadSource::pluck('id')->all();
        $managers = [$sales->id, $sales2->id];
        $guardians = collect();
        $students = collect();
        for ($i = 0; $i < 100; $i++) {
            $branch = $branches[$i % 2];
            // siblings share a parent every ~5th student
            if ($i % 5 === 4 && $guardians->isNotEmpty()) {
                $g = $guardians->last();
                $ln = explode(' ', $g->full_name)[0];
            } else {
                $ln = $last[$i % 10];
                $g = Guardian::create(['full_name' => $ln.' '.$this->pick(['Bahrom', 'Anvar', 'Rustam', 'Dilfuza', 'Gulbahor', 'Sanjar']), 'phone' => '+99893'.mt_rand(1000000, 9999999), 'telegram' => null]);
                $guardians->push($g);
            }
            $s = Student::create([
                'branch_id' => $branch->id, 'source_id' => $this->pick($sources), 'manager_id' => $this->pick($managers),
                'first_name' => $first[$i % 20], 'last_name' => $ln, 'birth_date' => now()->subYears(mt_rand(7, 28))->subDays(mt_rand(0, 300))->toDateString(),
                'gender' => $i % 2 ? 'female' : 'male', 'phone' => '+99890'.mt_rand(1000000, 9999999), 'status' => 'active',
                'registered_at' => $start->copy()->addDays(mt_rand(0, 40))->toDateString(), 'status_changed_at' => $start->toDateString(),
            ]);
            $s->guardians()->attach($g->id, ['relation' => $this->pick(['Отец', 'Мать']), 'is_primary' => true]);
            $students->push($s);
        }

        // ---- enrollments + payments
        $enroll = app(EnrollmentService::class);
        $pay = app(PaymentService::class);
        $methods = PaymentMethod::pluck('id')->all();
        $enrollments = collect();
        foreach ($students as $i => $s) {
            $group = $groups[$i % 9];     // the 10th group is "enrolling" and stays empty-ish
            $enrollments->push($enroll->enroll($s, $group, [
                'joined_at' => $start->copy()->addDays(mt_rand(0, 14))->toDateString(),
                'discount' => $i % 12 === 0 ? 50000 : 0,
                'next_payment_date' => now()->addDays(mt_rand(-20, 25))->toDateString(),
            ]));
        }
        foreach ($enrollments as $i => $gs) {
            $due = $gs->charge();
            $scenario = $i % 10;
            $parts = match (true) {
                $scenario < 5 => [$due],                                    // fully paid at once
                $scenario < 7 => [round($due / 2, -3), $due - round($due / 2, -3)],   // paid in two parts
                $scenario < 9 => [round($due * mt_rand(40, 70) / 100, -3)], // partial → debt
                default => [],                                                // nothing paid → debt
            };
            foreach ($parts as $k => $amount) {
                if ($amount > 0) {
                    $pay->record(['student_id' => $gs->student_id, 'group_id' => $gs->group_id, 'amount' => $amount, 'method_id' => $this->pick($methods),
                        'paid_at' => now()->subDays(mt_rand(1, 40) - $k)->setTime(mt_rand(9, 18), mt_rand(0, 59))], $admin->id);
                }
            }
        }
        // a few refunds / corrections
        foreach ([3, 17, 42] as $idx) {
            $gs = $enrollments[$idx];
            $pay->record(['student_id' => $gs->student_id, 'group_id' => $gs->group_id, 'amount' => 50000, 'type' => 'refund', 'method_id' => $methods[0], 'comment' => 'Возврат за пропущенное занятие'], $admin->id);
        }

        // ---- attendance for past lessons
        $att = app(AttendanceService::class);
        Lesson::whereDate('lesson_date', '<', today())->where('status', 'planned')->with('group')->get()->each(function (Lesson $l) use ($att, $admin) {
            $ids = \App\Models\GroupStudent::where('group_id', $l->group_id)->whereIn('status', ['active'])->pluck('student_id');
            $rows = [];
            foreach ($ids as $sid) {
                $r = mt_rand(1, 100);
                $rows[$sid] = ['status' => $r <= 80 ? 'present' : ($r <= 87 ? 'late' : ($r <= 96 ? 'absent' : 'excused')), 'comment' => null];
            }
            if ($rows) {
                $att->mark($l, $rows, $admin->id);
            }
        });
        // one cancelled lesson in the past and one upcoming
        Lesson::whereDate('lesson_date', '<', today())->where('status', 'planned')->limit(2)->get()->each(fn ($l) => $schedule->cancel($l, 'Болезнь преподавателя'));

        // ---- leads (CRM)
        $leadSvc = app(LeadService::class);
        $courseIds = $courses->pluck('id')->all();
        $statuses = LeadStatus::all()->keyBy('slug');
        $order = ['new', 'new', 'in_work', 'contacted', 'interested', 'trial_booked', 'trial_attended', 'paid', 'no_answer', 'thinking', 'no_show', 'lost'];
        $leads = collect();
        for ($i = 0; $i < 60; $i++) {
            $lead = $leadSvc->create([
                'branch_id' => $branches[$i % 2]->id, 'first_name' => $first[($i * 7) % 20], 'last_name' => $last[($i * 3) % 10],
                'phone' => '+99891'.mt_rand(1000000, 9999999), 'source_id' => $this->pick($sources), 'course_id' => $this->pick($courseIds),
                'manager_id' => $this->pick($managers), 'age' => mt_rand(8, 30), 'instagram' => $i % 3 === 0 ? 'user'.$i : null,
                'next_contact_at' => now()->addDays(mt_rand(-3, 5)), 'comment' => $i % 4 === 0 ? 'Просит перезвонить вечером' : null,
            ], $this->pick($managers), false);
            $lead->forceFill(['created_at' => now()->subDays(mt_rand(0, 28)), 'updated_at' => now()])->saveQuietly();
            $slug = $order[$i % count($order)];
            if ($slug !== 'new') {
                $leadSvc->changeStatus($lead, $statuses[$slug]);
            }
            $leads->push($lead->fresh());
        }
        // convert a few "paid" leads into students (full chain Lead → Student → Group → Payment)
        foreach ($leads->where('status_id', $statuses['paid']->id)->take(4) as $lead) {
            $student = $leadSvc->convert($lead, ['group_id' => $groups[$lead->id % 9]->id]);
            $gs = $student->enrollments()->first();
            $pay->record(['student_id' => $student->id, 'group_id' => $gs->group_id, 'amount' => $gs->charge(), 'method_id' => $methods[1]], $admin->id);
        }

        // ---- expenses
        $cats = ExpenseCategory::pluck('id', 'name');
        foreach (range(1, 28) as $i) {
            $cat = $this->pick(['Аренда', 'Реклама', 'Коммунальные', 'Интернет', 'Канцелярия', 'Оборудование', 'Ремонт', 'Прочее']);
            Expense::create(['branch_id' => $branches[$i % 2]->id, 'category_id' => $cats[$cat], 'method_id' => $this->pick($methods),
                'spent_at' => now()->subDays(mt_rand(0, 40))->toDateString(), 'amount' => mt_rand(8, 120) * 10000 * ($cat === 'Аренда' ? 10 : 1),
                'description' => $cat.' — демо', 'created_by' => $admin->id]);
        }

        // ---- salaries: accrue last month, pay about half
        $salary = app(SalaryService::class);
        $period = now()->subMonthNoOverflow()->format('Y-m');
        $salary->accrue($period);
        foreach (Teacher::take(5)->get() as $t) {
            $bal = $salary->balance($t);
            if ($bal['debt'] > 0) {
                $salary->pay($t, round($bal['debt'] / 2, -3), $methods[0], now()->toDateString(), 'Аванс (демо)');
            }
        }

        // a couple of bell notifications
        app(\App\Services\NotificationService::class)->system('Добро пожаловать в FLUGGI EDU ERP', 'Загружены demo-данные: 100 учеников, 10 групп, оплаты, посещаемость и лиды.', '/dashboard');
    }
}
