-- ═══════════════════════════════════════════════════════════════════════════
--  What is actually wrong with the vehicle.
--
--  Replaces the "was it in an accident?" yes/no on the booking form with a
--  single question that covers the real reasons somebody calls a truck. The
--  boolean stays: is_accident carries a pricing surcharge and puts a flag on
--  the board that a driver needs to see before rolling, so it is now DERIVED
--  from this column rather than asked separately. Dropping it would have
--  quietly removed a charge and hidden a hazard.
--
--  VARCHAR rather than an ENUM. The list of problems will grow — this is the
--  sort of field a dispatcher asks to extend twice a year, and an ENUM makes
--  every one of those an ALTER on a live table.
-- ═══════════════════════════════════════════════════════════════════════════

SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'calls'
              AND column_name = 'problem');
SET @s := IF(@c = 0,
  'ALTER TABLE calls ADD COLUMN problem VARCHAR(40) NULL AFTER is_accident',
  'SELECT "calls.problem already present"');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Jobs booked before this existed. The only thing we can honestly say about
-- them is whether the accident box was ticked; everything else stays NULL
-- rather than being guessed at.
UPDATE calls SET problem = 'accident'
 WHERE problem IS NULL AND is_accident = 1;
