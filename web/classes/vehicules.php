<?php
class Vehicle {
    private PDO $pdo;

    const TYPES = [
        'car'        => 'Car',
        'van'        => 'Van',
        'pickup'     => 'Pickup Truck',
        'minibus'    => 'Minibus',
        'bike'       => 'Bike',
        'motorcycle' => 'Motorcycle',
        'other'      => 'Other',
    ];
    const LUGGAGE = [
        'none'   => 'No luggage',
        'small'  => 'Small (cabin bag)',
        'medium' => 'Medium (1 suitcase)',
        'large'  => 'Large (2 suitcases)',
        'xl'     => 'XL (3+ / moving)',
    ];

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function getByUser(int $userId): array {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM vehicles WHERE user_id=? ORDER BY id DESC");
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { return []; }
    }

    public function getById(int $id): ?array {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM vehicles WHERE id=?");
            $stmt->execute([$id]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) { return null; }
    }

    public function save(array $d, int $userId): bool {
        try {
            if (!empty($d['id'])) {
                $stmt = $this->pdo->prepare(
                    "UPDATE vehicles SET type=?,make=?,model=?,year=?,color=?,plate_number=?,
                     seats=?,has_ac=?,luggage=?,max_weight_kg=?,has_wifi=?,is_accessible=?,notes=?
                     WHERE id=? AND user_id=?"
                );
                return $stmt->execute([
                    $d['type'], $d['make'] ?? '', $d['model'] ?? '', (int)($d['year'] ?? 0),
                    $d['color'] ?? '', $d['plate_number'] ?? '', (int)$d['seats'],
                    (int)($d['has_ac'] ?? 0), $d['luggage'] ?? 'medium',
                    (float)($d['max_weight_kg'] ?? 0), (int)($d['has_wifi'] ?? 0),
                    (int)($d['is_accessible'] ?? 0), $d['notes'] ?? '',
                    (int)$d['id'], $userId
                ]);
            }
            $stmt = $this->pdo->prepare(
                "INSERT INTO vehicles (user_id,type,make,model,year,color,plate_number,seats,
                 has_ac,luggage,max_weight_kg,has_wifi,is_accessible,notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            return $stmt->execute([
                $userId, $d['type'], $d['make'] ?? '', $d['model'] ?? '',
                (int)($d['year'] ?? 0), $d['color'] ?? '', $d['plate_number'] ?? '',
                (int)$d['seats'], (int)($d['has_ac'] ?? 0), $d['luggage'] ?? 'medium',
                (float)($d['max_weight_kg'] ?? 0), (int)($d['has_wifi'] ?? 0),
                (int)($d['is_accessible'] ?? 0), $d['notes'] ?? ''
            ]);
        } catch (PDOException $e) {
            error_log("Vehicle::save — " . $e->getMessage());
            return false;
        }
    }

    public function delete(int $id, int $userId): bool {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM vehicles WHERE id=? AND user_id=?");
            return $stmt->execute([$id, $userId]) && $stmt->rowCount() > 0;
        } catch (PDOException $e) { return false; }
    }

    public static function typeIcon(string $type): string {
        return match($type) {
            'van', 'minibus'        => 'fa-shuttle-van',
            'bike', 'motorcycle'    => 'fa-motorcycle',
            'pickup'                => 'fa-truck-pickup',
            default                 => 'fa-car',
        };
    }
}
